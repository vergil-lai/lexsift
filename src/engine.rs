//! 基于 Aho–Corasick 的词库编译、白名单过滤和原文范围处理。

use crate::normalize::{Normalized, Options, normalize, normalized_chars};
use aho_corasick::{AhoCorasick, AhoCorasickBuilder, Match, MatchKind};
use std::cmp::Reverse;
use std::collections::HashSet;

/// 一次有效命中在原始 UTF-8 文本中的范围。
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct Hit {
    /// 首次保留的词库项编号，用于取回配置时的原词。
    pub term_id: usize,
    /// 原文起始字节偏移，包含在命中范围内。
    pub start: usize,
    /// 原文结束字节偏移，不包含在命中范围内。
    pub end: usize,
}

/// 原词与对应的已编译自动机；空词库不构造自动机。
struct Dictionary {
    originals: Vec<String>,
    matcher: Option<AhoCorasick>,
}

impl Dictionary {
    /// 按词库顺序归一化并编译，归一化重复时保留首次出现的原词。
    ///
    /// # Errors
    ///
    /// 任一词归一化后为空，或自动机编译失败时返回错误。
    fn compile(entries: Vec<String>, options: &Options) -> Result<Self, String> {
        let mut seen = HashSet::new();
        let mut originals = Vec::new();
        let mut patterns = Vec::new();

        for (index, entry) in entries.into_iter().enumerate() {
            let pattern = normalize(&entry, options).text;
            if pattern.is_empty() {
                return Err(format!(
                    "dictionary entry at index {index} is empty after normalization"
                ));
            }
            if seen.insert(pattern.clone()) {
                originals.push(entry);
                patterns.push(pattern);
            }
        }

        let matcher = if patterns.is_empty() {
            None
        } else {
            Some(
                AhoCorasickBuilder::new()
                    .match_kind(MatchKind::Standard)
                    .build(patterns)
                    .map_err(|error| error.to_string())?,
            )
        };

        Ok(Self { originals, matcher })
    }
}

/// 一个白名单起点及从首项到该项的最大结束位置，坐标属于归一化文本。
#[derive(Clone, Copy)]
struct WhitelistRange {
    start: usize,
    prefix_max_end: usize,
}

/// 单个 Matcher 实例独占的词库、白名单和归一化配置。
pub(crate) struct Engine {
    terms: Dictionary,
    whitelist: Dictionary,
    options: Options,
}

impl Engine {
    /// 编译两份词库并构造独立的匹配引擎。
    ///
    /// # Errors
    ///
    /// 敏感词或白名单词库包含无效项，或自动机编译失败时返回错误。
    pub(crate) fn new(
        terms: Vec<String>,
        whitelist: Vec<String>,
        options: Options,
    ) -> Result<Self, String> {
        let terms = Dictionary::compile(terms, &options)?;
        let whitelist = Dictionary::compile(whitelist, &options)?;
        Ok(Self {
            terms,
            whitelist,
            options,
        })
    }

    /// 判断是否存在未被白名单完整覆盖的命中。
    ///
    /// 无白名单时流式归一化并匹配，不构造全文或来源映射。
    /// 白名单可能依赖后续文本，因此保留完整归一化及覆盖检查。
    pub(crate) fn contains(&self, text: &str) -> bool {
        let Some(matcher) = &self.terms.matcher else {
            return false;
        };
        if self.whitelist.matcher.is_none() {
            return contains_chars(matcher, normalized_chars(text, &self.options));
        }
        let normalized = normalize(text, &self.options);
        let whitelist = self.whitelist_ranges(&normalized.text);
        has_match(self.valid_matches(&normalized, &whitelist))
    }

    /// 扫描所有重叠命中，并将其映射回原始 UTF-8 字节范围。
    ///
    /// 结果按起点升序、同起点较长范围优先排列；同一词在同一原文范围
    /// 的重复结果只保留一次。
    pub(crate) fn scan(&self, text: &str) -> Vec<Hit> {
        let normalized = normalize(text, &self.options);
        let whitelist = self.whitelist_ranges(&normalized.text);
        let mut hits: Vec<_> = self
            .valid_matches(&normalized, &whitelist)
            .map(|matched| {
                let source = normalized.source_span(matched.start(), matched.end());
                Hit {
                    term_id: matched.pattern().as_usize(),
                    start: source.start,
                    end: source.end,
                }
            })
            .collect();

        hits.sort_unstable_by_key(|hit| (hit.start, Reverse(hit.end), hit.term_id));
        hits.dedup_by_key(|hit| (hit.term_id, hit.start, hit.end));
        hits
    }

    /// 将重叠或相邻的有效原文范围合并，每个合并范围替换一次。
    ///
    /// 空 `replacement` 删除命中范围；未命中的原文字节原样保留。
    pub(crate) fn mask(&self, text: &str, replacement: &str) -> String {
        let hits = self.scan(text);
        let mut ranges = Vec::new();
        for hit in hits {
            if let Some((_, end)) = ranges.last_mut()
                && hit.start <= *end
            {
                *end = (*end).max(hit.end);
            } else {
                ranges.push((hit.start, hit.end));
            }
        }

        let mut output = String::with_capacity(text.len());
        let mut copied_through = 0;
        for (start, end) in ranges {
            output.push_str(&text[copied_through..start]);
            output.push_str(replacement);
            copied_through = end;
        }
        output.push_str(&text[copied_through..]);
        output
    }

    /// 按命中编号返回词库中首次保留的原词。
    ///
    /// # Panics
    ///
    /// `term_id` 不属于当前词库时会触发越界 panic；正常编号只来自当前自动机。
    pub(crate) fn term(&self, term_id: usize) -> &str {
        &self.terms.originals[term_id]
    }

    /// 先完整编译新敏感词词库，成功后才替换当前词库。
    ///
    /// # Errors
    ///
    /// 新词库无效或编译失败时返回错误，当前词库保持可用。
    pub(crate) fn replace_terms(&mut self, terms: Vec<String>) -> Result<(), String> {
        let replacement = Dictionary::compile(terms, &self.options)?;
        self.terms = replacement;
        Ok(())
    }

    /// 先完整编译新白名单，成功后才替换当前白名单。
    ///
    /// # Errors
    ///
    /// 新白名单无效或编译失败时返回错误，当前白名单保持可用。
    pub(crate) fn replace_whitelist(&mut self, whitelist: Vec<String>) -> Result<(), String> {
        let replacement = Dictionary::compile(whitelist, &self.options)?;
        self.whitelist = replacement;
        Ok(())
    }

    /// 收集白名单在归一化文本中的重叠命中，建立单区间覆盖查询索引。
    fn whitelist_ranges(&self, text: &str) -> Vec<WhitelistRange> {
        let Some(matcher) = &self.whitelist.matcher else {
            return Vec::new();
        };
        let mut ranges: Vec<_> = matcher
            .find_overlapping_iter(text)
            .map(|matched| (matched.start(), matched.end()))
            .collect();
        ranges.sort_unstable_by_key(|&(start, end)| (start, Reverse(end)));

        let mut prefix_max_end = 0;
        ranges
            .into_iter()
            .map(|(start, end)| {
                prefix_max_end = prefix_max_end.max(end);
                WhitelistRange {
                    start,
                    prefix_max_end,
                }
            })
            .collect()
    }

    /// 惰性遍历所有敏感词命中，只保留未被白名单覆盖的项。
    fn valid_matches<'a>(
        &'a self,
        normalized: &'a Normalized,
        whitelist: &'a [WhitelistRange],
    ) -> impl Iterator<Item = Match> + 'a {
        self.terms
            .matcher
            .iter()
            .flat_map(move |matcher| matcher.find_overlapping_iter(&normalized.text))
            .filter(move |matched| !is_whitelisted(*matched, whitelist))
    }
}

/// 只对稳定的归一化 UTF-8 字节分批，保留最长词减一个字节的重叠区。
/// 每批至少与最长词等长，故重叠区的重复检索总量不超过新字节量。
/// 使用内存检索保留自动机的预过滤加速，避免无命中时逐字节遍历状态。
fn contains_chars(matcher: &AhoCorasick, chars: impl Iterator<Item = char>) -> bool {
    let mut bytes = chars.flat_map(|ch| {
        let mut encoded = [0; 4];
        let len = ch.encode_utf8(&mut encoded).len();
        encoded.into_iter().take(len)
    });
    let overlap = matcher.max_pattern_len() - 1;
    let batch_len = matcher.max_pattern_len().max(256);
    let mut buffer = Vec::new();
    loop {
        let previous_len = buffer.len();
        buffer.extend(bytes.by_ref().take(batch_len));
        if buffer.len() == previous_len {
            return false;
        }
        if matcher.is_match(&buffer) {
            return true;
        }
        let retained = overlap.min(buffer.len());
        let start = buffer.len() - retained;
        buffer.copy_within(start.., 0);
        buffer.truncate(retained);
    }
}

/// 仅当某一个白名单区间完整包含命中时返回 `true`。
///
/// 前缀最大结束位置代表真实存在的单个区间，不会把多个白名单区间合并。
fn is_whitelisted(matched: Match, whitelist: &[WhitelistRange]) -> bool {
    let ranges_before_end = whitelist.partition_point(|range| range.start <= matched.start());
    ranges_before_end > 0 && whitelist[ranges_before_end - 1].prefix_max_end >= matched.end()
}

/// 读取惰性迭代器的首项；不会为判断存在性收集完整结果。
fn has_match(mut matches: impl Iterator<Item = Match>) -> bool {
    matches.any(|_| true)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::Cell;

    #[test]
    fn streaming_match_stops_consuming_after_a_bounded_prefix() {
        let matcher = AhoCorasick::new(["ab"]).unwrap();
        let visits = Cell::new(0);
        let chars = "ab".chars().chain(std::iter::repeat('文')).inspect(|_| {
            visits.set(visits.get() + 1);
            assert!(visits.get() <= 256, "matcher consumed the unbounded tail");
        });
        assert!(contains_chars(&matcher, chars));
        assert!(visits.get() < 256);
    }

    #[test]
    fn contains_matches_scan_across_stream_boundaries_and_all_options() {
        let cases = [
            ("a", "a\u{0301}"),
            ("à", "a\u{0315}\u{0300}"),
            ("가", "ﾡￂ"),
            ("가", "ﾡ👨‍👩‍👧‍👦ￂ"),
            ("ᄀ", "ﾡￂ"),
            ("ffi", "ﬃ"),
            ("i\u{0307}", "İ"),
            ("赌博", "赌1️⃣🇨🇳👍🏽博"),
            ("ab", "A —$ B"),
            ("\0", "\0"),
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
            for (term, input) in cases {
                let engine = Engine::new(vec![term.into()], vec![], options.clone()).unwrap();
                for padding in [0, 250, 255, 256, 257] {
                    let text = format!("{}{input}{}", "x".repeat(padding), "文".repeat(300));
                    assert_eq!(
                        engine.contains(&text),
                        !engine.scan(&text).is_empty(),
                        "options={bits}, term={term:?}, input={input:?}, padding={padding}"
                    );
                }
                assert!(!engine.contains(""));
            }
        }
    }

    #[test]
    fn streaming_match_handles_long_patterns_and_unbounded_combining_sequences() {
        for length in [255, 256, 257, 8193] {
            let term = "a".repeat(length);
            let engine = Engine::new(vec![term.clone()], vec![], Options::default()).unwrap();
            for text in [
                format!("前{term}后"),
                format!("{}b", "a".repeat(length - 1)),
            ] {
                assert_eq!(engine.contains(&text), !engine.scan(&text).is_empty());
            }
        }
        let marks = format!("a{}\u{0300}", "\u{0315}".repeat(10000));
        for term in ["a", "à"] {
            let engine = Engine::new(vec![term.into()], vec![], Options::default()).unwrap();
            assert_eq!(engine.contains(&marks), !engine.scan(&marks).is_empty());
        }
    }

    #[test]
    fn whitelist_after_a_long_prefix_can_exempt_an_early_match() {
        let text = format!("ab{}safe", "文".repeat(10000));
        let engine =
            Engine::new(vec!["ab".into()], vec![text.clone()], Options::default()).unwrap();
        assert!(!engine.contains(&text));
        assert!(engine.contains(&format!("{text}ab")));
    }

    #[test]
    fn builds_matches_masks_and_replaces_terms_atomically() {
        let mut a = Engine::new(vec!["赌博".into()], vec![], Options::default()).unwrap();
        let b = Engine::new(vec!["微信".into()], vec![], Options::default()).unwrap();

        assert!(a.contains("赌 博"));
        assert!(!b.contains("赌 博"));
        assert_eq!(a.mask("前赌 博后", ""), "前后");
        assert!(a.replace_terms(vec![" ".into()]).is_err());
        assert!(a.contains("赌博"));
        a.replace_terms(vec!["微信".into()]).unwrap();
        assert!(!a.contains("赌博"));
    }

    #[test]
    fn whitelist_must_individually_contain_the_normalized_match() {
        let a = Engine::new(vec!["i".into()], vec!["f".into()], Options::default()).unwrap();
        assert!(a.contains("ﬁ"));

        let a = Engine::new(
            vec!["abcd".into()],
            vec!["ab".into(), "cd".into()],
            Options::default(),
        )
        .unwrap();
        assert!(a.contains("abcd"));
    }

    #[test]
    fn whitelist_filters_all_contained_matches_but_not_a_later_match() {
        let a = Engine::new(vec!["ab".into()], vec!["xab".into()], Options::default()).unwrap();

        assert!(a.contains("xab ab"));
        assert_eq!(
            a.scan("xab ab"),
            vec![Hit {
                term_id: 0,
                start: 4,
                end: 6,
            }]
        );

        let all_whitelisted =
            Engine::new(vec!["ab".into()], vec!["xab".into()], Options::default()).unwrap();
        assert!(!all_whitelisted.contains("xab"));
        assert!(all_whitelisted.scan("xab").is_empty());
    }

    #[test]
    fn scan_sorts_by_source_range_and_deduplicates_exact_hits() {
        let a = Engine::new(
            vec!["a".into(), "ab".into(), "b".into()],
            vec![],
            Options::default(),
        )
        .unwrap();
        assert_eq!(
            a.scan("ab"),
            vec![
                Hit {
                    term_id: 1,
                    start: 0,
                    end: 2,
                },
                Hit {
                    term_id: 0,
                    start: 0,
                    end: 1,
                },
                Hit {
                    term_id: 2,
                    start: 1,
                    end: 2,
                },
            ]
        );

        let expanded = Engine::new(vec!["f".into()], vec![], Options::default()).unwrap();
        assert_eq!(
            expanded.scan("ﬀ"),
            vec![Hit {
                term_id: 0,
                start: 0,
                end: 3,
            }]
        );
    }

    #[test]
    fn mask_merges_overlapping_and_adjacent_source_ranges() {
        let a = Engine::new(
            vec!["ab".into(), "bc".into(), "cd".into()],
            vec![],
            Options::default(),
        )
        .unwrap();
        assert_eq!(a.mask("abcd", "替换"), "替换");

        let adjacent =
            Engine::new(vec!["a".into(), "b".into()], vec![], Options::default()).unwrap();
        assert_eq!(adjacent.mask("ab", "*"), "*");
    }

    #[test]
    fn duplicate_normalized_terms_keep_the_first_original() {
        let a = Engine::new(vec!["ﬁ".into(), "fi".into()], vec![], Options::default()).unwrap();

        assert_eq!(a.scan("fi")[0].term_id, 0);
        assert_eq!(a.term(0), "ﬁ");
    }

    #[test]
    fn whitelist_replacement_is_atomic() {
        let mut a = Engine::new(vec!["ab".into()], vec!["ab".into()], Options::default()).unwrap();
        assert!(!a.contains("ab"));

        assert!(a.replace_whitelist(vec![" ".into()]).is_err());
        assert!(!a.contains("ab"));

        a.replace_whitelist(vec![]).unwrap();
        assert!(a.contains("ab"));
    }

    #[test]
    fn empty_normalized_entry_error_reports_index_and_replacement_is_atomic() {
        let error = Engine::new(vec!["valid".into(), " ".into()], vec![], Options::default())
            .err()
            .expect("second term must fail normalization");
        assert_eq!(
            error,
            "dictionary entry at index 1 is empty after normalization"
        );

        let mut engine =
            Engine::new(vec!["赌博".into()], vec!["微信".into()], Options::default()).unwrap();
        let error = engine
            .replace_terms(vec!["微信".into(), " ".into()])
            .expect_err("second replacement term must fail normalization");
        assert_eq!(
            error,
            "dictionary entry at index 1 is empty after normalization"
        );
        assert!(engine.contains("赌博"));

        let error = engine
            .replace_whitelist(vec!["安全".into(), " ".into()])
            .expect_err("second replacement whitelist entry must fail normalization");
        assert_eq!(
            error,
            "dictionary entry at index 1 is empty after normalization"
        );
        assert!(!engine.contains("微信"));
    }

    #[test]
    fn empty_term_dictionary_has_no_matches() {
        let a = Engine::new(vec![], vec![], Options::default()).unwrap();

        assert!(!a.contains("anything"));
        assert!(a.scan("anything").is_empty());
        assert_eq!(a.mask("anything", "*"), "anything");
    }

    #[test]
    fn contains_stops_after_the_first_valid_match() {
        let a = Engine::new(
            vec!["ab".into(), "bc".into(), "cd".into()],
            vec![],
            Options::default(),
        )
        .unwrap();
        assert!(a.scan("abcd").len() > 1);

        let normalized = normalize("abcd", &a.options);
        let whitelist = a.whitelist_ranges(&normalized.text);
        let visits = Cell::new(0);
        let matches = a.valid_matches(&normalized, &whitelist).inspect(|_| {
            visits.set(visits.get() + 1);
        });

        assert!(has_match(matches));
        assert_eq!(visits.get(), 1);
    }

    #[test]
    fn builds_and_matches_ten_thousand_terms() {
        let terms = (0..10_000).map(|i| format!("term{i:05}")).collect();
        let a = Engine::new(terms, vec![], Options::default()).unwrap();

        assert!(a.contains("prefix term09999 suffix"));
        assert_eq!(a.term(9_999), "term09999");
    }
}
