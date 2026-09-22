//! `LexSift\Matcher` 的 PHP 边界、参数校验及模块信息。

use crate::engine::Engine;
use crate::normalize::{Options, normalize};
use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::FromZval;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::array::Iter;
use ext_php_rs::types::{ZendHashTable, ZendLong, Zval};
use ext_php_rs::zend::{ModuleEntry, ce};
use ext_php_rs::{info_table_end, info_table_header, info_table_row, info_table_start};

/// 借用 PHP 字符串原始字节，在入口处显式校验 UTF-8。
///
/// 此类型只用于 Rust 绑定，不注册为 PHP 类。
pub struct PhpString<'a>(&'a [u8]);

/// 借用词库数组，避免转换非法 UTF-8 数组键时触发 panic。
///
/// 解析词库时忽略键，只检查每个值是否为合法 UTF-8 字符串。
pub struct DictionaryInput<'a>(Option<&'a ZendHashTable>);

/// 借用 options 数组，以便逐项校验键和值而不进行隐式转换。
pub struct OptionsInput<'a>(Option<&'a ZendHashTable>);

impl<'a> PhpString<'a> {
    /// 返回合法 UTF-8 文本，损坏字节转换为 PHP `ValueError`。
    fn utf8(&self, parameter: &str) -> PhpResult<&'a str> {
        std::str::from_utf8(self.0)
            .map_err(|_| value_error(format!("{parameter} must contain valid UTF-8")))
    }
}

impl<'a> FromZval<'a> for PhpString<'a> {
    const TYPE: DataType = DataType::String;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        BinarySlice::<u8>::from_zval(zval).map(|bytes| Self(bytes.into()))
    }
}

impl<'a> From<&'a str> for PhpString<'a> {
    fn from(value: &'a str) -> Self {
        Self(value.as_bytes())
    }
}

impl<'a> FromZval<'a> for DictionaryInput<'a> {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.array().map(|array| Self(Some(array)))
    }
}

impl From<[(); 0]> for DictionaryInput<'_> {
    fn from(_: [(); 0]) -> Self {
        Self(None)
    }
}

impl<'a> FromZval<'a> for OptionsInput<'a> {
    const TYPE: DataType = DataType::Array;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        zval.array().map(|array| Self(Some(array)))
    }
}

impl From<[(); 0]> for OptionsInput<'_> {
    fn from(_: [(); 0]) -> Self {
        Self(None)
    }
}

/// 独立持有词库、白名单与自动机的 PHP Matcher 实例。
#[php_class]
#[php(name = "LexSift\\Matcher")]
pub struct Matcher {
    engine: Engine,
}

#[php_impl]
impl Matcher {
    /// 从敏感词、白名单及可选归一化配置构造实例。
    ///
    /// 数组值必须是字符串；未知配置键、无效 UTF-8 和归一化后为空的词
    /// 分别转换为 PHP 标准异常。
    #[php(defaults(whitelist = [], options = []))]
    pub fn __construct(
        terms: DictionaryInput<'_>,
        whitelist: DictionaryInput<'_>,
        options: OptionsInput<'_>,
    ) -> PhpResult<Self> {
        let options = parse_options(options)?;
        let terms = parse_dictionary(terms, "terms", Some(&options))?;
        let whitelist = parse_dictionary(whitelist, "whitelist", Some(&options))?;
        let engine = Engine::new(terms, whitelist, options)
            .map_err(|error| value_error(format!("invalid dictionary: {error}")))?;
        Ok(Self { engine })
    }

    /// 判断原文是否包含至少一个未被白名单完整覆盖的词。
    pub fn contains(&self, text: PhpString<'_>) -> PhpResult<bool> {
        Ok(self.engine.contains(text.utf8("text")?))
    }

    /// 返回所有有效命中，每项包含原词、原文片段及左闭右开的字节偏移。
    pub fn scan(&self, text: PhpString<'_>) -> PhpResult<Vec<ZBox<ZendHashTable>>> {
        let text = text.utf8("text")?;
        self.engine
            .scan(text)
            .into_iter()
            .map(|hit| {
                let start = zend_offset(hit.start, "start")?;
                let end = zend_offset(hit.end, "end")?;
                let mut row = ZendHashTable::with_capacity(4);
                row.insert("term", self.engine.term(hit.term_id))?;
                row.insert("text", &text[hit.start..hit.end])?;
                row.insert("start", start)?;
                row.insert("end", end)?;
                Ok(row)
            })
            .collect()
    }

    /// 在原文上合并并替换有效命中范围；空 replacement 表示删除。
    #[php(defaults(replacement = "*".into()))]
    pub fn mask(&self, text: PhpString<'_>, replacement: PhpString<'_>) -> PhpResult<String> {
        Ok(self
            .engine
            .mask(text.utf8("text")?, replacement.utf8("replacement")?))
    }

    /// 成功编译后替换本实例的敏感词词库，失败时保留原词库。
    pub fn replace_terms(&mut self, terms: DictionaryInput<'_>) -> PhpResult<()> {
        let terms = parse_dictionary(terms, "terms", None)?;
        self.engine
            .replace_terms(terms)
            .map_err(|error| value_error(format!("terms contains an invalid entry: {error}")))
    }

    /// 成功编译后替换本实例的白名单，失败时保留原白名单。
    pub fn replace_whitelist(&mut self, whitelist: DictionaryInput<'_>) -> PhpResult<()> {
        let whitelist = parse_dictionary(whitelist, "whitelist", None)?;
        self.engine
            .replace_whitelist(whitelist)
            .map_err(|error| value_error(format!("whitelist contains an invalid entry: {error}")))
    }
}

/// 解析词库数组的值，并在可用时用同一归一化配置提前检查空词。
///
/// 原始数组键不参与匹配，也不转为要求 UTF-8 的高层 `ArrayKey`。
fn parse_dictionary(
    values: DictionaryInput<'_>,
    parameter: &str,
    options: Option<&Options>,
) -> PhpResult<Vec<String>> {
    let Some(values) = values.0 else {
        return Ok(Vec::new());
    };
    let mut parsed = Vec::with_capacity(values.len());
    let mut values = Iter::new(values);
    while let Some((_key, value)) = values.next_zval() {
        let index = parsed.len();
        let value = PhpString::from_zval(value)
            .ok_or_else(|| type_error(format!("{parameter}[{index}] must be of type string")))?;
        let value = value.utf8(&format!("{parameter}[{index}]"))?;
        if options.is_some_and(|options| normalize(value, options).text.is_empty()) {
            return Err(value_error(format!(
                "{parameter}[{index}] is empty after normalization"
            )));
        }
        parsed.push(value.to_owned());
    }
    Ok(parsed)
}

/// 从 options 数组解析六个布尔选项，未提供的选项沿用默认值。
fn parse_options(values: OptionsInput<'_>) -> PhpResult<Options> {
    let mut options = Options::default();
    let Some(values) = values.0 else {
        return Ok(options);
    };
    let mut values = Iter::new(values);
    for index in 0..values.len() {
        let (key, value) = values
            .next_zval()
            .expect("array length changed during iteration");
        if key.is_long() {
            return Err(value_error(format!(
                "options key at position {index} must be a string"
            )));
        }
        let key = PhpString::from_zval(&key)
            .expect("Zend returned an array key that is neither string nor integer");
        let key = key.utf8(&format!("options key at position {index}"))?;
        let value = value
            .bool()
            .ok_or_else(|| type_error(format!("options[{key}] must be of type bool")))?;
        match key {
            "unicode_nfkc" => options.unicode_nfkc = value,
            "lowercase" => options.lowercase = value,
            "remove_whitespace" => options.remove_whitespace = value,
            "remove_punctuation" => options.remove_punctuation = value,
            "remove_symbols" => options.remove_symbols = value,
            "remove_emoji" => options.remove_emoji = value,
            _ => return Err(value_error(format!("options contains unknown key {key}"))),
        }
    }
    Ok(options)
}

/// 在写入 PHP 整数前检查 Rust 字节偏移是否超出 Zend 整数范围。
fn zend_offset(offset: usize, name: &str) -> PhpResult<ZendLong> {
    ZendLong::try_from(offset)
        .map_err(|_| value_error(format!("match {name} offset exceeds PHP integer range")))
}

/// 构造 PHP 标准 `TypeError`。
fn type_error(message: String) -> PhpException {
    PhpException::new(message, 0, ce::type_error())
}

/// 构造 PHP 标准 `ValueError`。
fn value_error(message: String) -> PhpException {
    PhpException::new(message, 0, ce::value_error())
}

/// 向 `php --ri lexsift` 输出扩展和 Unicode 实现版本。
///
/// # Safety
///
/// 此函数只作为 Zend 模块信息回调调用；`info_table_*` 宏要求当前线程
/// 处于有效的 PHP 模块信息输出上下文中。
unsafe extern "C" fn module_info(_module: *mut ModuleEntry) {
    info_table_start!();
    info_table_header!("lexsift support", "enabled");
    info_table_row!("lexsift version", env!("CARGO_PKG_VERSION"));
    info_table_row!("matching backend", "aho-corasick 1.1.4");
    info_table_row!(
        "normalization",
        "unicode-normalization 0.1.25 (Unicode 17.0.0)"
    );
    info_table_row!(
        "segmentation",
        "unicode-segmentation 1.13.3 (Unicode 17.0.0)"
    );
    info_table_row!(
        "Unicode properties",
        "regex 1.12.4 / regex-syntax 0.8.11 (Unicode 16.0.0)"
    );
    info_table_end!();
}

#[php_module]
/// 注册 PHP 类及模块信息回调的 ext-php-rs 模块入口。
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<Matcher>().info_function(module_info)
}
