<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    nowPlaying: { type: Object, default: null },
    queue: { type: Array, default: () => [] },
    onlineUsers: { type: Array, default: () => [] },
    voteStatus: { type: Object, default: () => ({ current: 0, required: 0 }) },
});

const page = usePage();
const guest = computed(() => page.props.guest);
const station = computed(() => page.props.station);

// Local reactive state hydrated from props, then kept live via Echo.
const state = reactive({
    nowPlaying: props.nowPlaying,
    queue: [...props.queue],
    onlineUsers: [...props.onlineUsers],
    vote: { ...props.voteStatus },
    stationStatus: station.value?.status ?? 'on',
});

const isPlaying = ref(false);
const audio = ref(null);

const uploadForm = useForm({ track: null, title: '' });

const fmt = (s) => {
    if (!s) return '0:00';
    const m = Math.floor(s / 60);
    const sec = String(s % 60).padStart(2, '0');
    return `${m}:${sec}`;
};

const togglePlay = () => {
    if (!audio.value) return;
    if (audio.value.paused) {
        audio.value.play();
        isPlaying.value = true;
    } else {
        audio.value.pause();
        isPlaying.value = false;
    }
};

const submitTrack = () => {
    uploadForm.post('/tracks', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
};

const onFile = (e) => {
    uploadForm.track = e.target.files[0] ?? null;
};

const voteSkip = () => {
    router.post('/skip', {}, { preserveScroll: true, preserveState: true });
};

const leave = () => {
    router.post('/leave');
};

// ---- Realtime ----------------------------------------------------------
let channel = null;
let heartbeat = null;

onMounted(() => {
    // Keep last_active_at fresh so we stay counted among active listeners.
    heartbeat = setInterval(() => {
        if (window.axios) window.axios.post('/heartbeat').catch(() => {});
    }, 15000);

    if (!window.Echo) return;
    channel = window.Echo.channel('station');

    channel.listen('.TrackChanged', (e) => {
        state.nowPlaying = e.nowPlaying;
        state.vote = e.voteStatus ?? { current: 0, required: 0 };
    });
    channel.listen('.QueueUpdated', (e) => {
        state.queue = e.queue ?? [];
    });
    channel.listen('.VoteCountUpdated', (e) => {
        state.vote = { current: e.current, required: e.required };
    });
    channel.listen('.UserJoined', (e) => {
        if (!state.onlineUsers.some((u) => u.id === e.user.id)) {
            state.onlineUsers.push(e.user);
        }
    });
    channel.listen('.UserLeft', (e) => {
        state.onlineUsers = state.onlineUsers.filter((u) => u.id !== e.userId);
    });
    channel.listen('.StationToggled', (e) => {
        state.stationStatus = e.status;
    });
    channel.listen('.UserKicked', (e) => {
        if (guest.value && e.userId === guest.value.id) {
            router.visit('/blocked');
        }
    });
});

onBeforeUnmount(() => {
    if (heartbeat) clearInterval(heartbeat);
    if (window.Echo) window.Echo.leave('station');
});
</script>

<template>
    <Head title="Live Player" />

    <div class="min-h-screen max-w-5xl mx-auto p-6 space-y-6">
        <!-- Header -->
        <header class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🎧</span>
                <div>
                    <h1 class="text-xl font-semibold text-cyan-400">Jamdio</h1>
                    <p class="text-xs text-slate-400">
                        Welcome, <span class="text-slate-200">{{ guest?.nickname }}</span>
                    </p>
                </div>
            </div>
            <button class="text-sm text-slate-400 hover:text-rose-400" @click="leave">Leave</button>
        </header>

        <div
            v-if="state.stationStatus !== 'on'"
            class="rounded-lg bg-amber-500/10 ring-1 ring-amber-500/40 px-4 py-3 text-amber-300 text-sm"
        >
            The station is currently offline. Playback is paused.
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <!-- Player + now playing -->
            <section class="md:col-span-2 space-y-6">
                <div class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Now playing</p>
                    <h2 class="mt-1 text-lg font-medium text-slate-100">
                        {{ state.nowPlaying?.title ?? 'Fallback playlist' }}
                    </h2>
                    <p class="text-sm text-slate-400">
                        <template v-if="state.nowPlaying?.uploaded_by">
                            submitted by {{ state.nowPlaying.uploaded_by }}
                        </template>
                        <template v-else>background music</template>
                    </p>

                    <div class="mt-4 flex items-center gap-4">
                        <button
                            class="rounded-full bg-cyan-500 w-12 h-12 text-slate-950 text-xl hover:bg-cyan-400"
                            @click="togglePlay"
                        >
                            {{ isPlaying ? '⏸' : '▶' }}
                        </button>
                        <audio ref="audio" :src="station?.streamUrl" preload="none"></audio>
                        <span class="text-xs text-slate-500">Live stream · synchronized</span>
                    </div>
                </div>

                <!-- Skip vote -->
                <div class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-200">Skip this track?</p>
                        <p class="text-xs text-slate-400">
                            {{ state.vote.current }}/{{ state.vote.required }} votes needed
                        </p>
                    </div>
                    <button
                        class="rounded-lg bg-slate-800 px-4 py-2 text-sm text-slate-100 ring-1 ring-slate-700 hover:bg-slate-700 disabled:opacity-40"
                        :disabled="!state.nowPlaying"
                        @click="voteSkip"
                    >
                        ⏭ Vote skip
                    </button>
                </div>

                <!-- Submit track -->
                <div class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6">
                    <p class="text-sm font-medium text-slate-200 mb-3">Submit a track</p>
                    <form class="space-y-3" @submit.prevent="submitTrack">
                        <input
                            v-model="uploadForm.title"
                            type="text"
                            placeholder="Title (optional)"
                            class="w-full rounded-lg bg-slate-800 px-3 py-2 text-sm text-slate-100 ring-1 ring-slate-700 outline-none focus:ring-cyan-500"
                        />
                        <input type="file" accept="audio/mpeg,.mp3" class="block w-full text-sm text-slate-400" @change="onFile" />
                        <p v-if="uploadForm.errors.track" class="text-xs text-rose-400">{{ uploadForm.errors.track }}</p>
                        <button
                            type="submit"
                            :disabled="uploadForm.processing || !uploadForm.track"
                            class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-cyan-400 disabled:opacity-50"
                        >
                            {{ uploadForm.processing ? 'Uploading…' : 'Add to queue' }}
                        </button>
                    </form>
                </div>

                <!-- Queue -->
                <div class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6">
                    <p class="text-sm font-medium text-slate-200 mb-3">Up next ({{ state.queue.length }})</p>
                    <ol class="space-y-2">
                        <li
                            v-for="(t, i) in state.queue"
                            :key="t.queue_id"
                            class="flex items-center justify-between text-sm text-slate-300"
                        >
                            <span class="truncate"><span class="text-slate-500 mr-2">{{ i + 1 }}.</span>{{ t.title }}</span>
                            <span class="text-xs text-slate-500">{{ fmt(t.duration) }}</span>
                        </li>
                        <li v-if="!state.queue.length" class="text-sm text-slate-500">Queue is empty — playing fallback music.</li>
                    </ol>
                </div>
            </section>

            <!-- Online users -->
            <aside class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6 h-fit">
                <p class="text-sm font-medium text-slate-200 mb-3">
                    Online ({{ state.onlineUsers.length }})
                </p>
                <ul class="space-y-2">
                    <li v-for="u in state.onlineUsers" :key="u.id" class="flex items-center gap-2">
                        <img v-if="u.avatar_path" :src="u.avatar_path" class="w-7 h-7 rounded-full" />
                        <span class="text-sm text-slate-300">{{ u.nickname }}</span>
                    </li>
                </ul>
            </aside>
        </div>
    </div>
</template>
