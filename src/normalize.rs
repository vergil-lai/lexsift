//! Unicode 归一化与归一化字节到原文 grapheme 的来源映射。

use regex::Regex;
use std::sync::OnceLock;
use unicode_normalization::UnicodeNormalization;
use unicode_normalization::char::{canonical_combining_class, compose, decompose_compatible};
use unicode_segmentation::UnicodeSegmentation;

/// 原始 UTF-8 文本中的左闭右开字节范围。
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct Span {
    /// 范围起始字节偏移。
    pub start: usize,
    /// 范围结束字节偏移。
    pub end: usize,
}

/// 词库、白名单和输入文本共用的归一化选项。
#[derive(Clone, Debug)]
pub(crate) struct Options {
    /// 启用全串 Unicode NFKC 兼容归一化。
    pub unicode_nfkc: bool,
    /// 启用 Unicode 逐码点小写转换，不进行 case folding。
    pub lowercase: bool,
    /// 移除 Unicode 空白字符。
    pub remove_whitespace: bool,
    /// 移除 Unicode `P` 类标点。
    pub remove_punctuation: bool,
    /// 移除 Unicode `S` 类符号。
    pub remove_symbols: bool,
    /// 按原始 extended grapheme 整簇移除 emoji。
    pub remove_emoji: bool,
}

impl Default for Options {
    fn default() -> Self {
        Self {
            unicode_nfkc: true,
            lowercase: true,
            remove_whitespace: true,
            remove_punctuation: false,
            remove_symbols: false,
            remove_emoji: true,
        }
    }
}

/// 归一化文本及其每个 UTF-8 字节对应的原文来源范围。
///
/// 同一原文 grapheme 展开成多个归一化字符时，它们共享来源范围。
pub(crate) struct Normalized {
    /// 供自动机检索的归一化 UTF-8 文本。
    pub text: String,
    /// 与 `text` 的字节位置一一对应的原始 grapheme 范围。
    pub spans: Vec<Span>,
}

impl Normalized {
    /// 将非空的归一化字节范围映射为最小的原文覆盖范围。
    ///
    /// NFKC 的组合与重排可能使来源顺序不单调，因此要检查范围内全部字节。
    ///
    /// # Panics
    ///
    /// `start >= end` 或 `end` 超出归一化文本字节长度时触发 panic。
    pub(crate) fn source_span(&self, start: usize, end: usize) -> Span {
        assert!(start < end, "normalized source range must not be empty");
        assert!(
            end <= self.spans.len(),
            "normalized source range is out of bounds"
        );

        self.spans[start..end].iter().copied().fold(
            Span {
                start: usize::MAX,
                end: 0,
            },
            |combined, span| Span {
                start: combined.start.min(span.start),
                end: combined.end.max(span.end),
            },
        )
    }
}

/// 携带完整原始 grapheme 来源范围的一个 Unicode 标量值。
#[derive(Clone, Copy)]
struct TaggedChar {
    ch: char,
    span: Span,
}

/// 供 emoji、标点和符号过滤共用的 Unicode 属性正则。
struct UnicodeClasses {
    emoji: Regex,
    punctuation: Regex,
    symbol: Regex,
}

/// 惰性初始化进程内只读属性分类器。
fn unicode_classes() -> &'static UnicodeClasses {
    static CLASSES: OnceLock<UnicodeClasses> = OnceLock::new();

    CLASSES.get_or_init(|| UnicodeClasses {
        emoji: Regex::new(
            r"[\p{Extended_Pictographic}\p{Emoji_Presentation}\p{Emoji_Modifier}\p{Regional_Indicator}\u{FE0F}\u{20E3}]",
        )
        .expect("the static emoji regex must compile"),
        punctuation: Regex::new(r"\p{P}").expect("the static punctuation regex must compile"),
        symbol: Regex::new(r"\p{S}").expect("the static symbol regex must compile"),
    })
}

/// 无来源映射的惰性全串归一化；只有已稳定的 NFKC 输出才交给匹配器。
///
/// grapheme 仅用于原文 emoji 过滤，不能分别 NFKC：兼容韩文等可跨簇组合，
/// 删除 emoji 也可能让两侧字符参与组合。库迭代器负责分解、重排及组合的前瞻。
pub(crate) fn normalized_chars<'a>(
    input: &'a str,
    options: &'a Options,
) -> impl Iterator<Item = char> + 'a {
    let chars = input
        .graphemes(true)
        .filter(move |grapheme| {
            !options.remove_emoji || !unicode_classes().emoji.is_match(grapheme)
        })
        .flat_map(str::chars);
    let chars = if options.unicode_nfkc {
        CharIter::Transformed(chars.nfkc())
    } else {
        CharIter::Original(chars)
    };
    let chars = if options.lowercase {
        CharIter::Transformed(chars.flat_map(char::to_lowercase))
    } else {
        CharIter::Original(chars)
    };
    chars.filter(move |&ch| keep_char(ch, options))
}

/// 可选变换的静态分派，避免在每个字符上通过 boxed iterator 动态调用。
enum CharIter<I, T> {
    Original(I),
    Transformed(T),
}

impl<I: Iterator<Item = char>, T: Iterator<Item = char>> Iterator for CharIter<I, T> {
    type Item = char;

    fn next(&mut self) -> Option<char> {
        match self {
            Self::Original(iter) => iter.next(),
            Self::Transformed(iter) => iter.next(),
        }
    }
}

/// NFKC 和小写展开之后的可选类别过滤，供两条归一化路径共用。
fn keep_char(ch: char, options: &Options) -> bool {
    let mut encoded = [0; 4];
    let encoded = ch.encode_utf8(&mut encoded);
    !(options.remove_whitespace && ch.is_whitespace()
        || options.remove_punctuation && unicode_classes().punctuation.is_match(encoded)
        || options.remove_symbols && unicode_classes().symbol.is_match(encoded))
}

/// 按同一配置归一化输入，并记录每个输出字节的原文来源。
///
/// 先按原始 grapheme 删除 emoji，再对保留字符执行全串 NFKC、逐码点小写及
/// 可选类别过滤。原始 grapheme 内的字符共享来源范围，替换时不会留下半个簇。
pub(crate) fn normalize(input: &str, options: &Options) -> Normalized {
    let mut tagged = Vec::new();

    for (start, grapheme) in input.grapheme_indices(true) {
        if options.remove_emoji && unicode_classes().emoji.is_match(grapheme) {
            continue;
        }

        let span = Span {
            start,
            end: start + grapheme.len(),
        };
        tagged.extend(grapheme.chars().map(|ch| TaggedChar { ch, span }));
    }

    if options.unicode_nfkc {
        tagged = tagged_nfkc(tagged);
    }

    if options.lowercase {
        tagged = tagged
            .into_iter()
            .flat_map(|tagged| {
                tagged
                    .ch
                    .to_lowercase()
                    .map(move |ch| TaggedChar { ch, ..tagged })
            })
            .collect();
    }

    tagged.retain(|tagged| keep_char(tagged.ch, options));

    let mut text = String::new();
    let mut spans = Vec::new();
    for tagged in tagged {
        text.push(tagged.ch);
        spans.extend(std::iter::repeat_n(tagged.span, tagged.ch.len_utf8()));
    }

    Normalized { text, spans }
}

/// 对带来源范围的全串字符流执行 NFKC 分解与规范组合。
///
/// 在整个流上处理可让相邻原始 grapheme 中的兼容韩文字母正确组合。
fn tagged_nfkc(input: Vec<TaggedChar>) -> Vec<TaggedChar> {
    let mut decomposed = Vec::new();
    let mut pending_start = 0;

    for tagged in input {
        decompose_compatible(tagged.ch, |ch| {
            if canonical_combining_class(ch) == 0 {
                decomposed[pending_start..]
                    .sort_by_key(|tagged: &TaggedChar| canonical_combining_class(tagged.ch));
                decomposed.push(TaggedChar { ch, ..tagged });
                pending_start = decomposed.len();
            } else {
                decomposed.push(TaggedChar { ch, ..tagged });
            }
        });
    }
    decomposed[pending_start..].sort_by_key(|tagged| canonical_combining_class(tagged.ch));

    recompose(decomposed)
}

/// 按 Unicode 规范组合阻塞规则重新组合标量值并合并来源范围。
fn recompose(input: Vec<TaggedChar>) -> Vec<TaggedChar> {
    let mut output: Vec<TaggedChar> = Vec::new();
    let mut composee = None;
    let mut last_ccc: Option<u8> = None;

    for tagged in input {
        let ccc = canonical_combining_class(tagged.ch);
        let Some(composee_index) = composee else {
            output.push(tagged);
            if ccc == 0 {
                composee = Some(output.len() - 1);
            }
            continue;
        };

        let blocked = last_ccc.is_some_and(|previous| previous >= ccc);
        if !blocked && let Some(composed) = compose(output[composee_index].ch, tagged.ch) {
            let composee = &mut output[composee_index];
            composee.ch = composed;
            composee.span = Span {
                start: composee.span.start.min(tagged.span.start),
                end: composee.span.end.max(tagged.span.end),
            };
            continue;
        }

        output.push(tagged);
        if ccc == 0 {
            composee = Some(output.len() - 1);
            last_ccc = None;
        } else {
            last_ccc = Some(ccc);
        }
    }

    output
}

#[cfg(test)]
mod tests {
    use super::*;
    use unicode_normalization::UnicodeNormalization;

    fn no_transform_options() -> Options {
        Options {
            unicode_nfkc: false,
            lowercase: false,
            remove_whitespace: false,
            remove_punctuation: false,
            remove_symbols: false,
            remove_emoji: false,
        }
    }

    #[test]
    fn streaming_normalization_matches_mapped_normalization_for_all_options() {
        let fragments = [
            "",
            "a",
            "A",
            "\0",
            "\r\n",
            "\u{00a0}",
            "—",
            "$\u{0301}",
            "ﬃİ",
            "Ａ",
            "\u{0315}\u{0300}",
            "a\u{0315}\u{0300}",
            "\u{1100}",
            "\u{1161}\u{11a8}",
            "ﾡ",
            "ￂ",
            "\u{11a8}",
            "👨‍👩‍👧‍👦",
            "👍🏽",
            "🇨🇳",
            "1️⃣",
            "©\u{fe0e}",
            "©\u{fe0f}",
            "🏴\u{e0067}\u{e0062}\u{e007f}",
            "\u{0301}",
            "赌 博",
        ];
        for bits in 0..64 {
            let options = Options {
                unicode_nfkc: bits & 1 != 0,
                lowercase: bits & 2 != 0,
                remove_whitespace: bits & 4 != 0,
                remove_punctuation: bits & 8 != 0,
                remove_symbols: bits & 16 != 0,
                remove_emoji: bits & 32 != 0,
            };
            for first in fragments {
                for second in fragments {
                    let input = format!("{first}{second}");
                    assert_eq!(
                        normalized_chars(&input, &options).collect::<String>(),
                        normalize(&input, &options).text,
                        "options={bits}, input={input:?}"
                    );
                }
            }
        }
    }

    #[test]
    fn normalizes_hangul_compatibility_jamo_with_combined_source_span() {
        let normalized = normalize("ﾡￂ", &Options::default());

        assert_eq!(normalized.text, "가");
        assert_eq!(normalized.source_span(0, 3), Span { start: 0, end: 6 });
    }

    #[test]
    fn expands_compatibility_and_lowercase_characters() {
        let normalized = normalize("ﬃİ", &Options::default());

        assert_eq!(normalized.text, "ffii\u{0307}");
        assert_eq!(normalized.source_span(0, 3), Span { start: 0, end: 3 });
        assert_eq!(normalized.source_span(3, 6), Span { start: 3, end: 5 });
    }

    #[test]
    fn removed_whitespace_is_included_between_matched_source_clusters() {
        let normalized = normalize("前赌 博后", &Options::default());

        assert_eq!(normalized.text, "前赌博后");
        assert_eq!(normalized.source_span(3, 9), Span { start: 3, end: 10 });
    }

    #[test]
    fn removes_each_supported_emoji_form_as_a_complete_grapheme() {
        let emoji = [
            "👨‍👩‍👧‍👦",
            "👍🏽",
            "🇨🇳",
            "1️⃣",
            "🏴\u{e0067}\u{e0062}\u{e0065}\u{e006e}\u{e0067}\u{e007f}",
            "©",
            "©\u{fe0e}",
            "©\u{fe0f}",
        ];

        for emoji in emoji {
            let input = format!("赌{emoji}博");
            assert_eq!(normalize(&input, &Options::default()).text, "赌博");
        }
    }

    #[test]
    fn does_not_remove_plain_keycap_bases_as_emoji() {
        assert_eq!(normalize("1#*", &Options::default()).text, "1#*");
    }

    #[test]
    fn removes_unicode_punctuation_and_symbols_independently() {
        let mut punctuation = no_transform_options();
        punctuation.remove_punctuation = true;
        assert_eq!(normalize("a—$b", &punctuation).text, "a$b");

        let mut symbols = no_transform_options();
        symbols.remove_symbols = true;
        assert_eq!(normalize("a—$b", &symbols).text, "a—b");
    }

    #[test]
    fn retained_combining_mark_maps_to_the_complete_original_grapheme() {
        let mut options = no_transform_options();
        options.remove_symbols = true;

        let normalized = normalize("$\u{0301}", &options);

        assert_eq!(normalized.text, "\u{0301}");
        assert_eq!(normalized.source_span(0, 2), Span { start: 0, end: 3 });
    }

    #[test]
    fn independent_edge_deletions_do_not_expand_the_match_source_span() {
        let normalized = normalize(" 赌 博 👨", &Options::default());

        assert_eq!(normalized.text, "赌博");
        assert_eq!(normalized.source_span(0, 6), Span { start: 1, end: 8 });
    }

    #[test]
    fn tagged_nfkc_matches_unicode_normalization_for_fixed_corpus() {
        let mut options = no_transform_options();
        options.unicode_nfkc = true;
        let corpus = [
            "\u{0315}\u{0300}",
            "a\u{0315}\u{0300}",
            "\u{1100}\u{1161}\u{11a8}",
            "ﾡￂ",
            "ﬃ",
            "İ",
            "\u{212b}",
            "x\u{0301}\u{0327}y",
        ];

        for input in corpus {
            let expected: String = input.nfkc().collect();
            assert_eq!(
                normalize(input, &options).text,
                expected,
                "input: {input:?}"
            );
        }
    }
}
