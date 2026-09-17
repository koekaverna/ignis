/* E19: three ways to undo a request's writes to the boot heap, measured against the same work.
 *   A. mprotect + SIGSEGV write barrier (the owner's proposal): cost is per dirty page, paid twice
 *      (fault + save, then restore).
 *   B. no barrier at all: keep a pristine copy and memcpy the WHOLE arena back at request end.
 *      Cost is O(arena), independent of how much was touched.
 *   C. soft-dirty bits (/proc/self/clear_refs + pagemap): the kernel tracks which pages were
 *      written, with no fault and no barrier; we read the bitmap at request end and restore those.
 * Build: cc -O2 -o /tmp/e19-cmp bench/e19/compare.c
 */
#define _GNU_SOURCE
#include <fcntl.h>
#include <signal.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <time.h>
#include <unistd.h>

static size_t PAGE, NPAGES;
static char *arena, *pristine, *undo;
static size_t *dirty;
static volatile size_t ndirty;   /* the handler writes it; without volatile the optimiser cached it and the restore loop saw 0 */
static long faults;

static double ns_since(struct timespec a) {
    struct timespec b; clock_gettime(CLOCK_MONOTONIC, &b);
    return (b.tv_sec - a.tv_sec) * 1e9 + (b.tv_nsec - a.tv_nsec);
}
static void on_segv(int s, siginfo_t *si, void *c) {
    (void)s; (void)c;
    size_t idx = ((char *)si->si_addr - arena) / PAGE;
    faults++;
    memcpy(undo + idx * PAGE, arena + idx * PAGE, PAGE);
    dirty[ndirty++] = idx;
    mprotect(arena + idx * PAGE, PAGE, PROT_READ | PROT_WRITE);
}
static void touch(size_t n, char v) {
    for (size_t i = 0; i < n; i++) arena[(i * 7 % NPAGES) * PAGE + 64] = v;
}

int main(int argc, char **argv) {
    size_t touched = argc > 1 ? (size_t)atoi(argv[1]) : 50;
    size_t arena_kb = argc > 2 ? (size_t)atoi(argv[2]) : 2048;
    size_t reqs = argc > 3 ? (size_t)atoi(argv[3]) : 1000;
    PAGE = (size_t)sysconf(_SC_PAGESIZE); NPAGES = arena_kb * 1024 / PAGE;
    arena    = mmap(NULL, NPAGES * PAGE, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    pristine = mmap(NULL, NPAGES * PAGE, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    undo     = mmap(NULL, NPAGES * PAGE, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);
    dirty = calloc(NPAGES, sizeof *dirty);
    memset(arena, 0xAB, NPAGES * PAGE); memcpy(pristine, arena, NPAGES * PAGE);
    struct sigaction sa = {0}; sa.sa_sigaction = on_segv; sa.sa_flags = SA_SIGINFO;
    sigaction(SIGSEGV, &sa, NULL);
    printf("arena=%zu KiB (%zu pages), touched=%zu pages/request, %zu requests\n", arena_kb, NPAGES, touched, reqs);

    /* A: barrier */
    double a_fault = 0, a_restore = 0;
    for (size_t r = 0; r < reqs; r++) {
        mprotect(arena, NPAGES * PAGE, PROT_READ); ndirty = 0;
        struct timespec t0; clock_gettime(CLOCK_MONOTONIC, &t0); touch(touched, (char)r); a_fault += ns_since(t0);
        struct timespec t1; clock_gettime(CLOCK_MONOTONIC, &t1);
        for (size_t i = 0; i < ndirty; i++) memcpy(arena + dirty[i] * PAGE, undo + dirty[i] * PAGE, PAGE);
        a_restore += ns_since(t1);
    }
    mprotect(arena, NPAGES * PAGE, PROT_READ | PROT_WRITE);
    printf("A mprotect+SIGSEGV   barrier %7.1f us   restore %6.1f us   TOTAL %7.1f us  (%.0f ns/fault)\n",
           a_fault / reqs / 1000, a_restore / reqs / 1000, (a_fault + a_restore) / reqs / 1000, a_fault / faults);

    /* B: whole-arena restore, no barrier */
    double b = 0;
    for (size_t r = 0; r < reqs; r++) {
        touch(touched, (char)r);
        struct timespec t0; clock_gettime(CLOCK_MONOTONIC, &t0);
        memcpy(arena, pristine, NPAGES * PAGE);
        b += ns_since(t0);
    }
    printf("B whole-arena memcpy barrier     0.0 us   restore %6.1f us   TOTAL %7.1f us\n", b / reqs / 1000, b / reqs / 1000);

    /* C: soft-dirty */
    int cr = open("/proc/self/clear_refs", O_WRONLY), pm = open("/proc/self/pagemap", O_RDONLY);
    if (cr < 0 || pm < 0) { printf("C soft-dirty          unavailable (/proc)\n"); return 0; }
    double c_clear = 0, c_scan = 0, c_restore = 0; size_t found = 0;
    uint64_t *ents = calloc(NPAGES, sizeof *ents);
    for (size_t r = 0; r < reqs; r++) {
        struct timespec t0; clock_gettime(CLOCK_MONOTONIC, &t0);
        if (write(cr, "4\n", 2) < 0) { printf("C soft-dirty          clear_refs write failed\n"); return 0; }
        c_clear += ns_since(t0);
        touch(touched, (char)r);
        struct timespec t1; clock_gettime(CLOCK_MONOTONIC, &t1);
        ssize_t got = pread(pm, ents, NPAGES * sizeof *ents, ((uintptr_t)arena / PAGE) * sizeof *ents);
        size_t n = 0;
        for (size_t i = 0; i < (size_t)(got / 8); i++) if (ents[i] & (1ULL << 55)) n++;
        c_scan += ns_since(t1); found = n;
        struct timespec t2; clock_gettime(CLOCK_MONOTONIC, &t2);
        for (size_t i = 0, seen = 0; i < (size_t)(got / 8) && seen < n; i++)
            if (ents[i] & (1ULL << 55)) { memcpy(arena + i * PAGE, pristine + i * PAGE, PAGE); seen++; }
        c_restore += ns_since(t2);
    }
    printf("C soft-dirty         clear %6.1f us   scan %6.1f us  restore %6.1f us   TOTAL %7.1f us  (saw %zu dirty pages)\n",
           c_clear / reqs / 1000, c_scan / reqs / 1000, c_restore / reqs / 1000,
           (c_clear + c_scan + c_restore) / reqs / 1000, found);
    return 0;
}
