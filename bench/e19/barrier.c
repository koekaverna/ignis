/* E19 feasibility: what does the write barrier cost per request?
 * mprotect the boot arena read-only, fault on first write to each page, copy the page into an undo
 * log, unprotect it; at request end copy the dirty pages back and re-protect. The owner's budget is
 * < 100 us per request at < 50 dirty pages — this measures exactly that loop, with nothing else in it.
 * Build: cc -O2 -o /tmp/e19-barrier bench/e19/barrier.c
 */
#define _GNU_SOURCE
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <time.h>
#include <unistd.h>

static size_t PAGE;
static char *arena;
static size_t arena_pages;
static char *undo;            /* undo log: one page slot per arena page */
static size_t *dirty;         /* indices of dirtied pages, in fault order */
static size_t ndirty;
static long faults;

static void on_segv(int sig, siginfo_t *si, void *ctx) {
    (void)sig; (void)ctx;
    size_t off = (char *)si->si_addr - arena;
    size_t idx = off / PAGE;
    faults++;
    memcpy(undo + idx * PAGE, arena + idx * PAGE, PAGE);   /* save the pristine page */
    dirty[ndirty++] = idx;
    mprotect(arena + idx * PAGE, PAGE, PROT_READ | PROT_WRITE);
}

static double ns_since(struct timespec a) {
    struct timespec b; clock_gettime(CLOCK_MONOTONIC, &b);
    return (b.tv_sec - a.tv_sec) * 1e9 + (b.tv_nsec - a.tv_nsec);
}

int main(int argc, char **argv) {
    size_t touched = argc > 1 ? (size_t)atoi(argv[1]) : 50;
    size_t reqs = argc > 2 ? (size_t)atoi(argv[2]) : 2000;
    PAGE = (size_t)sysconf(_SC_PAGESIZE);
    arena_pages = 512;                                  /* a 2 MiB boot arena */
    arena = mmap(NULL, arena_pages * PAGE, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    undo  = mmap(NULL, arena_pages * PAGE, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    dirty = calloc(arena_pages, sizeof *dirty);
    memset(arena, 0xAB, arena_pages * PAGE);

    struct sigaction sa = {0};
    sa.sa_sigaction = on_segv; sa.sa_flags = SA_SIGINFO;
    sigaction(SIGSEGV, &sa, NULL);

    double total = 0, restore_total = 0;
    for (size_t r = 0; r < reqs; r++) {
        mprotect(arena, arena_pages * PAGE, PROT_READ);
        ndirty = 0;
        struct timespec t0; clock_gettime(CLOCK_MONOTONIC, &t0);
        for (size_t i = 0; i < touched; i++) {           /* the "request" writes to N boot pages */
            arena[(i * 7 % arena_pages) * PAGE + 64] = (char)r;
        }
        double barrier_ns = ns_since(t0);
        struct timespec t1; clock_gettime(CLOCK_MONOTONIC, &t1);
        for (size_t i = 0; i < ndirty; i++) {            /* request end: put the pages back */
            memcpy(arena + dirty[i] * PAGE, undo + dirty[i] * PAGE, PAGE);
        }
        double restore_ns = ns_since(t1);
        total += barrier_ns; restore_total += restore_ns;
    }
    mprotect(arena, arena_pages * PAGE, PROT_READ | PROT_WRITE);
    printf("pages_touched=%zu requests=%zu faults=%ld\n", touched, reqs, faults);
    printf("  barrier (faults + save):   %.1f us per request  (%.0f ns per page)\n", total / reqs / 1000.0, total / faults);
    printf("  restore (copy back):       %.1f us per request\n", restore_total / reqs / 1000.0);
    printf("  TOTAL per request:         %.1f us   (budget: 100)\n", (total + restore_total) / reqs / 1000.0);
    return 0;
}
