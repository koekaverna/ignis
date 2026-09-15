/* Bindgen entry point. Only headers from the ZTS embed build in
 * /opt/php85-zts/include/php are used. Macros that bindgen cannot expand are
 * materialised as constants below so Rust never re-derives them by hand. */
#include <sapi/embed/php_embed.h>
#include <main/php_main.h>
#include <main/php_streams.h>
#include <Zend/zend_API.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_fibers.h>
#include <Zend/zend_observer.h>
#include <Zend/zend_interfaces.h>
#include <Zend/zend_ini.h>
#include <Zend/zend_extensions.h>
#include <TSRM/TSRM.h>

static const char *const IGNIS_ZEND_MODULE_BUILD_ID = ZEND_MODULE_BUILD_ID;
static const unsigned int IGNIS_ZEND_MODULE_API_NO = ZEND_MODULE_API_NO;
static const unsigned char IGNIS_ZEND_DEBUG = ZEND_DEBUG;
static const unsigned char IGNIS_USING_ZTS = USING_ZTS;
static const unsigned long IGNIS_SIZEOF_ZEND_MODULE_ENTRY = sizeof(zend_module_entry);
static const unsigned int IGNIS_IS_ARRAY_EX = IS_ARRAY_EX;
static const unsigned int IGNIS_IS_STRING_EX = IS_STRING_EX;
static const unsigned int IGNIS_IS_OBJECT_EX = IS_OBJECT_EX;
static const unsigned int IGNIS_ZEND_CALL_FRAME_SLOT = ZEND_CALL_FRAME_SLOT;
static const unsigned int IGNIS_GC_STRING = GC_STRING;
static const unsigned long IGNIS_ZSTR_STRUCT_HEADER = _ZSTR_HEADER_SIZE;

/* Backend (b): the true-async fork's scheduler ABI (php-src PR #22561). Only
 * present when PHP_CONFIG points at that build; ignis-sys emits
 * cfg(php_async_abi) in that case. */
#if __has_include(<Zend/zend_async_API.h>)
#include <Zend/zend_async_API.h>
#define IGNIS_HAS_ASYNC_ABI 1
/* Exported by patches/0001-test-scheduler-idle-hook.patch (not in a header). */
typedef bool (*ignis_ts_idle_hook_t)(void);
ZEND_API void test_scheduler_set_idle_hook(ignis_ts_idle_hook_t hook);
#else
#define IGNIS_HAS_ASYNC_ABI 0
#endif
static const int IGNIS_HAS_ASYNC_ABI_CONST = IGNIS_HAS_ASYNC_ABI;
