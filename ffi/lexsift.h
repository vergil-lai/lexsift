/* ABI v1. All lengths and offsets are bytes; strings need not be NUL terminated.
 * Input pointers must remain valid for the call; handles must not be shared
 * concurrently or used after free. Free each returned buffer/matches once.
 * Match terms borrow the engine and expire on dictionary mutation or free.
 * Error codes: 0 success, 1 invalid input, 2 caught Rust panic.
 * Options bits: NFKC=1, lowercase=2, whitespace=4, punctuation=8,
 * symbols=16, emoji=32. PHP defaults: 39.
 */
typedef struct LexsiftEngine LexsiftEngine;
typedef struct { const char *ptr; size_t len; } LexsiftSlice;
typedef struct { char *ptr; size_t len; } LexsiftBuffer;
typedef struct { int code; LexsiftBuffer message; } LexsiftError;
typedef struct { LexsiftSlice term; size_t start; size_t end; } LexsiftMatch;
typedef struct { LexsiftMatch *ptr; size_t len; } LexsiftMatches;

unsigned int lexsift_abi_version(void);
LexsiftError lexsift_new(const LexsiftSlice *terms, size_t terms_len,
    const LexsiftSlice *whitelist, size_t whitelist_len,
    unsigned int options, LexsiftEngine **out);
LexsiftError lexsift_contains(LexsiftEngine *engine, LexsiftSlice text, unsigned char *out);
LexsiftError lexsift_scan(LexsiftEngine *engine, LexsiftSlice text, LexsiftMatches *out);
LexsiftError lexsift_mask(LexsiftEngine *engine, LexsiftSlice text, LexsiftSlice replacement, LexsiftBuffer *out);
LexsiftError lexsift_replace_terms(LexsiftEngine *engine, const LexsiftSlice *terms, size_t len);
LexsiftError lexsift_replace_whitelist(LexsiftEngine *engine, const LexsiftSlice *whitelist, size_t len);
void lexsift_free(LexsiftEngine *engine);
void lexsift_buffer_free(LexsiftBuffer buffer);
void lexsift_matches_free(LexsiftMatches matches);
