<script setup>
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    stationStatus: { type: String, default: 'on' },
    nowPlaying: { type: Object, default: null },
    queue: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    fallback: { type: Array, default: () => [] },
});

const state = reactive({
    stationStatus: props.stationStatus,
    nowPlaying: props.nowPlaying,
    queue: [...props.queue],
    users: [...props.users],
    fallback: [...props.fallback],
});

// Re-sync local state whenever Inertia delivers fresh props. Every admin action
// redirects back to the dashboard with updated props; without this the local
// `state` stays frozen at its initial values and the UI only changes on a manual
// page refresh. Echo broadcasts mutate `state` directly between prop updates.
watch(
    () => [props.stationStatus, props.nowPlaying, props.queue, props.users, props.fallback],
    () => {
        state.stationStatus = props.stationStatus;
        state.nowPlaying = props.nowPlaying;
        state.queue = [...props.queue];
        state.users = [...props.users];
        state.fallback = [...props.fallback];
    },
);

const opts = { preserveScroll: true, preserveState: true };

const toggleStation = () => router.post('/admin/station/toggle', {}, opts);
const forceSkip = () => router.post('/admin/skip', {}, opts);
const remove = (q) => router.delete(`/admin/queue/${q.queue_id}`, opts);
const kick = (u) => router.post(`/admin/users/${u.id}/kick`, {}, opts);
const block = (u) => router.post(`/admin/users/${u.id}/block`, {}, opts);
const unblock = (u) =>
    router.post(`/admin/users/${u.id}/unblock`, {}, { ...opts, onSuccess: () => (u.is_blocked = false) });
const removeUser = (u) => {
    if (!window.confirm(`Remove ${u.nickname}? This permanently deletes the guest.`)) return;
    router.delete(`/admin/users/${u.id}`, opts);
};
const logout = () => router.post('/admin/logout');

const move = (index, delta) => {
    const next = index + delta;
    if (next < 0 || next >= state.queue.length) return;
    const list = state.queue;
    [list[index], list[next]] = [list[next], list[index]];
    router.post('/admin/queue/reorder', { order: list.map((q) => q.queue_id) }, opts);
};

const when = (iso) => (iso ? new Date(iso).toLocaleTimeString() : '—');

const fileInput = ref(null);
const uploading = ref(false);

const uploadFallback = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    router.post(
        '/admin/fallback',
        { track: file },
        {
            ...opts,
            forceFormData: true,
            onStart: () => (uploading.value = true),
            onFinish: () => {
                uploading.value = false;
                if (fileInput.value) fileInput.value.value = '';
            },
        },
    );
};

const removeFallback = (name) => {
    if (!window.confirm(`Remove “${name}” from the fallback playlist?`)) return;
    router.delete(`/admin/fallback/${encodeURIComponent(name)}`, opts);
};

const fileSize = (bytes) => {
    if (!bytes) return '—';
    const mb = bytes / (1024 * 1024);
    return mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
};

const clock = (secs) => {
    if (!secs) return '—';
    const m = Math.floor(secs / 60);
    const s = String(secs % 60).padStart(2, '0');
    return `${m}:${s}`;
};

let channel = null;
onMounted(() => {
    if (!window.Echo) return;
    channel = window.Echo.channel('station');
    channel.listen('.QueueUpdated', (e) => (state.queue = e.queue ?? []));
    channel.listen('.TrackChanged', (e) => (state.nowPlaying = e.nowPlaying));
    channel.listen('.StationToggled', (e) => (state.stationStatus = e.status));

    channel.listen('.UserJoined', (e) => {
        if (!e.user) return;
        const existing = state.users.find((u) => u.id === e.user.id);
        if (existing) {
            existing.is_online = true;
        } else {
            state.users.unshift({ ...e.user, is_online: true, is_blocked: false });
        }
    });
    channel.listen('.UserLeft', (e) => {
        const u = state.users.find((x) => x.id === e.userId);
        if (u) u.is_online = false;
    });
    channel.listen('.UserKicked', (e) => {
        if (e.reason === 'removed') {
            state.users = state.users.filter((x) => x.id !== e.userId);
            return;
        }
        const u = state.users.find((x) => x.id === e.userId);
        if (!u) return;
        u.is_online = false;
        if (e.reason === 'blocked') u.is_blocked = true;
    });
});
onBeforeUnmount(() => window.Echo && window.Echo.leave('station'));
</script>

<template>
    <Head title="Admin · Jamdio" />

    <div class="min-h-screen max-w-6xl mx-auto p-6 space-y-6">
        <header class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-cyan-400">Admin control panel</h1>
            <div class="flex items-center gap-3">
                <button
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :class="state.stationStatus === 'on' ? 'bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-500/40' : 'bg-rose-500/20 text-rose-300 ring-1 ring-rose-500/40'"
                    @click="toggleStation"
                >
                    Station: {{ state.stationStatus === 'on' ? 'ON' : 'OFF' }} — toggle
                </button>
                <button class="text-sm text-slate-400 hover:text-slate-200" @click="logout">Logout</button>
            </div>
        </header>

        <!-- Now playing -->
        <section class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6 flex items-center justify-between">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Now playing</p>
                <p class="text-lg text-slate-100">{{ state.nowPlaying?.title ?? 'Fallback playlist' }}</p>
            </div>
            <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm text-slate-100 ring-1 ring-slate-700 hover:bg-slate-700" @click="forceSkip">
                ⏭ Force skip
            </button>
        </section>

        <!-- Fallback music -->
        <section class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <p class="text-sm font-medium text-slate-200">Fallback music ({{ state.fallback.length }})</p>
                    <p class="text-xs text-slate-500">Played on shuffle whenever the queue is empty.</p>
                </div>
                <label
                    class="cursor-pointer rounded-lg bg-cyan-500/20 px-4 py-2 text-sm font-medium text-cyan-300 ring-1 ring-cyan-500/40 hover:bg-cyan-500/30"
                    :class="uploading ? 'opacity-50 pointer-events-none' : ''"
                >
                    {{ uploading ? 'Uploading…' : '+ Add MP3' }}
                    <input ref="fileInput" type="file" accept="audio/mpeg,.mp3" class="hidden" @change="uploadFallback" />
                </label>
            </div>
            <ul class="space-y-2">
                <li v-for="t in state.fallback" :key="t.name" class="flex items-center justify-between gap-2 text-sm">
                    <span class="truncate text-slate-300">{{ t.name }}</span>
                    <div class="flex items-center gap-3 shrink-0 text-slate-500">
                        <span>{{ clock(t.duration) }}</span>
                        <span>{{ fileSize(t.size) }}</span>
                        <button class="px-2 text-rose-400 hover:text-rose-300" @click="removeFallback(t.name)">✕</button>
                    </div>
                </li>
                <li v-if="!state.fallback.length" class="text-sm text-slate-500">
                    No fallback tracks yet — the stream goes silent when the queue empties.
                </li>
            </ul>
        </section>

        <div class="grid lg:grid-cols-2 gap-6">
            <!-- Queue management -->
            <section class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6">
                <p class="text-sm font-medium text-slate-200 mb-3">Queue ({{ state.queue.length }})</p>
                <ol class="space-y-2">
                    <li v-for="(t, i) in state.queue" :key="t.queue_id" class="flex items-center justify-between gap-2 text-sm">
                        <span class="truncate text-slate-300"><span class="text-slate-500 mr-2">{{ i + 1 }}.</span>{{ t.title }}</span>
                        <div class="flex items-center gap-1 shrink-0">
                            <button class="px-2 text-slate-400 hover:text-slate-100" @click="move(i, -1)">↑</button>
                            <button class="px-2 text-slate-400 hover:text-slate-100" @click="move(i, 1)">↓</button>
                            <button class="px-2 text-rose-400 hover:text-rose-300" @click="remove(t)">✕</button>
                        </div>
                    </li>
                    <li v-if="!state.queue.length" class="text-sm text-slate-500">Queue is empty.</li>
                </ol>
            </section>

            <!-- User management -->
            <section class="rounded-2xl bg-slate-900/80 ring-1 ring-slate-800 p-6">
                <p class="text-sm font-medium text-slate-200 mb-3">Listeners</p>
                <table class="w-full text-sm">
                    <thead class="text-left text-slate-500">
                        <tr>
                            <th class="py-1">Nickname</th>
                            <th>IP</th>
                            <th>Joined</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in state.users" :key="u.id" class="border-t border-slate-800">
                            <td class="py-2 text-slate-200">
                                <span class="inline-block w-2 h-2 rounded-full mr-2" :class="u.is_online ? 'bg-emerald-400' : 'bg-slate-600'"></span>
                                {{ u.nickname }}
                                <span v-if="u.is_blocked" class="ml-1 text-xs text-rose-400">(blocked)</span>
                            </td>
                            <td class="text-slate-500">{{ u.ip_address }}</td>
                            <td class="text-slate-500">{{ when(u.joined_at) }}</td>
                            <td class="text-right space-x-2 whitespace-nowrap">
                                <button class="text-amber-400 hover:text-amber-300" @click="kick(u)">Kick</button>
                                <button v-if="!u.is_blocked" class="text-rose-400 hover:text-rose-300" @click="block(u)">Block</button>
                                <button v-else class="text-emerald-400 hover:text-emerald-300" @click="unblock(u)">Unblock</button>
                                <button class="text-rose-500 hover:text-rose-400" @click="removeUser(u)">Remove</button>
                            </td>
                        </tr>
                        <tr v-if="!state.users.length"><td colspan="4" class="py-2 text-slate-500">No listeners yet.</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>
