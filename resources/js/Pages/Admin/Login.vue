<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });
const submit = () => form.post('/admin/login');
</script>

<template>
    <Head title="Admin login" />

    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="w-full max-w-sm rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-8">
            <h1 class="text-xl font-semibold text-cyan-400">Admin control panel</h1>
            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm text-slate-300 mb-1">Password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        class="w-full rounded-lg bg-slate-800 px-3 py-2 text-slate-100 ring-1 ring-slate-700 outline-none focus:ring-cyan-500"
                    />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-rose-400">{{ form.errors.password }}</p>
                </div>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-cyan-500 px-4 py-2 font-medium text-slate-950 hover:bg-cyan-400 disabled:opacity-50"
                >
                    Sign in
                </button>
            </form>
        </div>
    </div>
</template>
