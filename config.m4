PHP_ARG_ENABLE([lexsift], [whether to enable lexsift],
  [AS_HELP_STRING([--enable-lexsift], [Enable lexsift])], [yes])

if test "$PHP_LEXSIFT" != "no"; then
  AC_PATH_PROG([CARGO], [cargo], [no])
  if test "$CARGO" = "no"; then
    AC_MSG_ERROR([cargo is required to build lexsift])
  fi
  PHP_SUBST([CARGO])
  PHP_SUBST([PHP_CONFIG])
  PHP_MODULES="$PHP_MODULES modules/lexsift.so"
  PHP_SUBST([PHP_MODULES])
  PHP_ADD_MAKEFILE_FRAGMENT([$abs_srcdir/Makefile.frag], [$abs_srcdir], [$abs_builddir])
fi
