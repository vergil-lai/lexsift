//! LexSift 的 Rust 实现与 PHP 扩展入口。
//!
//! 匹配和 Unicode 处理保留在内部模块；启用 `php` 特性时注册
//! `LexSift\Matcher`，关闭该特性时仍可独立运行核心单元测试。

#[cfg(any(feature = "php", test))]
mod engine;
#[cfg(any(feature = "php", test))]
mod normalize;

#[cfg(feature = "php")]
mod php;
