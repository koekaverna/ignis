/* Bindgen entry point. Only headers from the ZTS embed build in
 * /opt/php85-zts/include/php are used. Macros that bindgen cannot expand are
 * materialised as constants below so Rust never re-derives them by hand. */
#include <sapi/embed/php_embed.h>
#include <main/php_main.h>
#include <main/php_streams.h>
#include <ext/standard/file.h>
/* A4 (ADR-0018): php_socket { bsd_socket, type, error, blocking, zstream, std } and socket_ce,
 * so the sockets hook can recover the fd from a Socket object. Requires --enable-sockets, which
 * every build recipe in this repo passes. */
#include <ext/sockets/php_sockets.h>
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

/* Exported by Zend/zend_fibers.c in 8.5.10 (ZEND_API) but not declared in
 * zend_fibers.h; declared here so bindgen exposes it (used for E11). */
ZEND_API void zend_fiber_resume_exception(zend_fiber *fiber, zval *exception, zval *return_value);
