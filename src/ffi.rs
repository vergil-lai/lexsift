//! ABI v1 for PHP FFI. Callers obey the ownership/pointer contract in ffi/lexsift.h.
//! No PHP/Zend symbols are linked; all matching remains in the shared Engine.

use crate::engine::Engine;
use crate::normalize::Options;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::{ptr, slice, str};

#[repr(C)]
#[derive(Clone, Copy)]
pub struct LexsiftSlice {
    ptr: *const u8,
    len: usize,
}

#[repr(C)]
pub struct LexsiftBuffer {
    ptr: *mut u8,
    len: usize,
}

impl LexsiftBuffer {
    fn new(bytes: Vec<u8>) -> Self {
        let bytes = Box::leak(bytes.into_boxed_slice());
        Self {
            ptr: bytes.as_mut_ptr(),
            len: bytes.len(),
        }
    }
}

#[repr(C)]
pub struct LexsiftError {
    code: i32,
    message: LexsiftBuffer,
}

#[repr(C)]
pub struct LexsiftMatch {
    term: LexsiftSlice,
    start: usize,
    end: usize,
}

#[repr(C)]
pub struct LexsiftMatches {
    ptr: *mut LexsiftMatch,
    len: usize,
}

fn boundary(call: impl FnOnce() -> Result<(), String>) -> LexsiftError {
    let (code, message) = match catch_unwind(AssertUnwindSafe(call)) {
        Ok(Ok(())) => {
            return LexsiftError {
                code: 0,
                message: LexsiftBuffer {
                    ptr: ptr::null_mut(),
                    len: 0,
                },
            };
        }
        Ok(Err(message)) => (1, message),
        Err(_) => (2, "LexSift native panic".to_owned()),
    };
    LexsiftError {
        code,
        message: LexsiftBuffer::new(message.into_bytes()),
    }
}

// Zero-length slices may be NULL; non-empty slices must point to valid memory.
unsafe fn text<'a>(input: LexsiftSlice, parameter: &str) -> Result<&'a str, String> {
    let bytes = if input.len == 0 {
        &[]
    } else {
        if input.ptr.is_null() {
            return Err(format!("{parameter} pointer is null"));
        }
        unsafe { slice::from_raw_parts(input.ptr, input.len) }
    };
    str::from_utf8(bytes).map_err(|_| format!("{parameter} must contain valid UTF-8"))
}

unsafe fn dictionary(
    entries: *const LexsiftSlice,
    len: usize,
    parameter: &str,
) -> Result<Vec<String>, String> {
    if len == 0 {
        return Ok(Vec::new());
    }
    if entries.is_null() {
        return Err(format!("{parameter} pointer is null"));
    }
    unsafe { slice::from_raw_parts(entries, len) }
        .iter()
        .enumerate()
        .map(|(index, &entry)| {
            unsafe { text(entry, &format!("{parameter}[{index}]")) }.map(str::to_owned)
        })
        .collect()
}

#[unsafe(no_mangle)]
pub extern "C" fn lexsift_abi_version() -> u32 {
    1
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_new(
    terms: *const LexsiftSlice,
    terms_len: usize,
    whitelist: *const LexsiftSlice,
    whitelist_len: usize,
    flags: u32,
    out: *mut *mut Engine,
) -> LexsiftError {
    boundary(|| {
        if flags & !63 != 0 {
            return Err("unknown options bits".to_owned());
        }
        let options = Options {
            unicode_nfkc: flags & 1 != 0,
            lowercase: flags & 2 != 0,
            remove_whitespace: flags & 4 != 0,
            remove_punctuation: flags & 8 != 0,
            remove_symbols: flags & 16 != 0,
            remove_emoji: flags & 32 != 0,
        };
        let terms = unsafe { dictionary(terms, terms_len, "terms") }?;
        let whitelist = unsafe { dictionary(whitelist, whitelist_len, "whitelist") }?;
        let engine = Engine::new(terms, whitelist, options)
            .map_err(|error| format!("invalid dictionary: {error}"))?;
        unsafe { *out = Box::into_raw(Box::new(engine)) };
        Ok(())
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_contains(
    engine: *mut Engine,
    input: LexsiftSlice,
    out: *mut u8,
) -> LexsiftError {
    boundary(|| {
        let text = unsafe { text(input, "text") }?;
        unsafe { *out = u8::from((*engine).contains(text)) };
        Ok(())
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_scan(
    engine: *mut Engine,
    input: LexsiftSlice,
    out: *mut LexsiftMatches,
) -> LexsiftError {
    boundary(|| {
        let text = unsafe { text(input, "text") }?;
        let engine = unsafe { &*engine };
        let matches: Vec<_> = engine
            .scan(text)
            .into_iter()
            .map(|hit| {
                let term = engine.term(hit.term_id);
                LexsiftMatch {
                    term: LexsiftSlice {
                        ptr: term.as_ptr(),
                        len: term.len(),
                    },
                    start: hit.start,
                    end: hit.end,
                }
            })
            .collect();
        let matches = Box::leak(matches.into_boxed_slice());
        unsafe {
            *out = LexsiftMatches {
                ptr: matches.as_mut_ptr(),
                len: matches.len(),
            }
        };
        Ok(())
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_mask(
    engine: *mut Engine,
    input: LexsiftSlice,
    replacement: LexsiftSlice,
    out: *mut LexsiftBuffer,
) -> LexsiftError {
    boundary(|| {
        let replacement = unsafe { text(replacement, "replacement") }?;
        let text = unsafe { text(input, "text") }?;
        let masked = unsafe { (*engine).mask(text, replacement) };
        unsafe { *out = LexsiftBuffer::new(masked.into_bytes()) };
        Ok(())
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_replace_terms(
    engine: *mut Engine,
    entries: *const LexsiftSlice,
    len: usize,
) -> LexsiftError {
    boundary(|| {
        let entries = unsafe { dictionary(entries, len, "terms") }?;
        unsafe { (*engine).replace_terms(entries) }
            .map_err(|error| format!("terms contains an invalid entry: {error}"))
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_replace_whitelist(
    engine: *mut Engine,
    entries: *const LexsiftSlice,
    len: usize,
) -> LexsiftError {
    boundary(|| {
        let entries = unsafe { dictionary(entries, len, "whitelist") }?;
        unsafe { (*engine).replace_whitelist(entries) }
            .map_err(|error| format!("whitelist contains an invalid entry: {error}"))
    })
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_free(engine: *mut Engine) {
    if !engine.is_null() {
        drop(unsafe { Box::from_raw(engine) });
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_buffer_free(buffer: LexsiftBuffer) {
    if !buffer.ptr.is_null() {
        drop(unsafe { Box::from_raw(ptr::slice_from_raw_parts_mut(buffer.ptr, buffer.len)) });
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn lexsift_matches_free(matches: LexsiftMatches) {
    if !matches.ptr.is_null() {
        drop(unsafe { Box::from_raw(ptr::slice_from_raw_parts_mut(matches.ptr, matches.len)) });
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn input(text: &str) -> LexsiftSlice {
        LexsiftSlice {
            ptr: text.as_ptr(),
            len: text.len(),
        }
    }

    fn success(error: LexsiftError) {
        assert_eq!(error.code, 0);
        assert!(error.message.ptr.is_null());
    }

    #[test]
    fn abi_owns_outputs_and_preserves_state_after_failure() {
        unsafe {
            let mut engine = ptr::null_mut();
            success(lexsift_new(
                &input("BAD"),
                1,
                ptr::null(),
                0,
                39,
                &mut engine,
            ));
            let mut found = 0;
            success(lexsift_contains(engine, input("ＢＡＤ"), &mut found));
            assert_eq!(found, 1);
            let mut hits = LexsiftMatches {
                ptr: ptr::null_mut(),
                len: 0,
            };
            success(lexsift_scan(engine, input("bad"), &mut hits));
            assert_eq!(hits.len, 1);
            assert_eq!(((*hits.ptr).start, (*hits.ptr).end), (0, 3));
            assert_eq!(text((*hits.ptr).term, "term").unwrap(), "BAD");
            lexsift_matches_free(hits);
            let error = lexsift_replace_terms(engine, &input(" "), 1);
            assert_eq!(error.code, 1);
            lexsift_buffer_free(error.message);
            let mut output = LexsiftBuffer {
                ptr: ptr::null_mut(),
                len: 0,
            };
            success(lexsift_mask(engine, input("bad"), input("\0"), &mut output));
            assert_eq!(slice::from_raw_parts(output.ptr, output.len), b"\0");
            lexsift_buffer_free(output);
            let error = lexsift_contains(
                engine,
                LexsiftSlice {
                    ptr: b"\xff".as_ptr(),
                    len: 1,
                },
                &mut found,
            );
            assert_eq!(error.code, 1);
            lexsift_buffer_free(error.message);
            lexsift_free(engine);
            lexsift_free(ptr::null_mut());
        }
    }

    #[test]
    fn panic_does_not_unwind_through_c() {
        let error = boundary(|| panic!("test panic"));
        assert_eq!(error.code, 2);
        unsafe { lexsift_buffer_free(error.message) };
    }
}
