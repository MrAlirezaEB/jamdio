<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    avatars: { type: Array, default: () => [] },
});

const form = useForm({
    nickname: '',
    avatar_path: props.avatars[0]?.path ?? '',
});

const submit = () => form.post('/join');
</script>

<template>
    <Head title="Join the station" />

    <div class="min-h-screen flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-8 shadow-2xl">
            <h1 class="text-2xl font-semibold text-cyan-400">🎧 Jamdio</h1>
            <p class="mt-1 text-sm text-slate-400">
                Pick a nickname and an avatar to join the live session.
            </p>

            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <label class="block text-sm text-slate-300 mb-1">Nickname</label>
                    <input
                        v-model="form.nickname"
                        type="text"
                        maxlength="30"
                        class="w-full rounded-lg bg-slate-800 px-3 py-2 text-slate-100 outline-none ring-1 ring-slate-700 focus:ring-cyan-500"
                        placeholder="DJ Nightowl"
                    />
                    <p v-if="form.errors.nickname" class="mt-1 text-xs text-rose-400">
                        {{ form.errors.nickname }}
                    </p>
                </div>

                <div>
                    <label class="block text-sm text-slate-300 mb-2">Avatar</label>
                    <div class="grid grid-cols-6 gap-2">
                        <button
                            v-for="a in avatars"
                            :key="a.path"
                            type="button"
                            class="rounded-full overflow-hidden ring-2 transition"
                            :class="form.avatar_path === a.path ? 'ring-cyan-400 scale-110' : 'ring-transparent opacity-70 hover:opacity-100'"
                            @click="form.avatar_path = a.path"
                        >
                            <img :src="a.url" :alt="a.path" class="w-full aspect-square" />
                        </button>
                    </div>
                    <p v-if="form.errors.avatar_path" class="mt-1 text-xs text-rose-400">
                        {{ form.errors.avatar_path }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-cyan-500 px-4 py-2 font-medium text-slate-950 hover:bg-cyan-400 disabled:opacity-50"
                >
                    Join the station
                </button>
            </form>
        </div>
    </div>
</template>
