<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Access blocked" />

    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="max-w-md text-center">
            <div class="text-5xl">🚫</div>
            <h1 class="mt-4 text-2xl font-semibold text-rose-400">You've left the station</h1>
            <p class="mt-2 text-slate-400">
                Your session has ended or you have been removed by an admin.
            </p>
            <Link href="/" class="mt-6 inline-block rounded-lg bg-slate-800 px-4 py-2 text-sm text-slate-100 ring-1 ring-slate-700 hover:bg-slate-700">
                Back to start
            </Link>
        </div>
    </div>
</template>
