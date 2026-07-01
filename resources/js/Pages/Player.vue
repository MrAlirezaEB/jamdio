<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    nowPlaying: { type: Object, default: null },
    queue: { type: Array, default: () => [] },
    onlineUsers: { type: Array, default: () => [] },
    voteStatus: { type: Object, default: () => ({ current: 0, required: 0 }) },
    hasVoted: { type: Boolean, default: false },
    reactions: { type: Array, default: () => [] },
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
    voted: props.hasVoted,
    stationStatus: station.value?.status ?? 'on',
});

const isPlaying = ref(false);
const needsGesture = ref(false);
const connecting = ref(false);
const audio = ref(null);
const showUpload = ref(false);

const uploadForm = useForm({ track: null, title: '' });
const dragOver = ref(false);

const fmt = (s) => {
    if (!s) return '0:00';
    const m = Math.floor(s / 60);
    const sec = String(s % 60).padStart(2, '0');
    return `${m}:${sec}`;
};

const fileName = computed(() => uploadForm.track?.name ?? '');
const nowTitle = computed(() => state.nowPlaying?.title ?? 'Fallback playlist');
const nowBy = computed(() =>
    state.nowPlaying?.uploaded_by ? `submitted by ${state.nowPlaying.uploaded_by}` : 'background music',
);

const votePct = computed(() => {
    if (!state.vote.required) return 0;
    return Math.min(100, Math.round((state.vote.current / state.vote.required) * 100));
});

// ---- Equalizer animation ----------------------------------------------
// The bars are driven by the REAL audio via a Web Audio AnalyserNode, so they
// track the actual music. Icecast serves the stream with CORS headers (see
// docker/icecast/icecast.xml.template) and the <audio> carries
// crossorigin="anonymous", so getByteFrequencyData() returns live data rather
// than zeros.
//
// The one hazard: createMediaElementSource() re-routes the element's output
// through the AudioContext, and a suspended context leaves that graph — the
// only remaining audio path — silent. We defuse this by building the graph
// lazily, ONLY after resume() has put the context in the "running" state inside
// a user gesture (see setupAnalyser). Until then the bars animate off a
// synthetic waveform, so audio always plays even if analysis never comes up.
const BARS = 52;
const barRefs = ref([]);
let rafId = null;
let synthPhase = 0;

// Web Audio graph, created once on first successful gesture.
let audioCtx = null;
let analyser = null;
let freqData = null;

const setBarRef = (el, i) => {
    if (el) barRefs.value[i] = el;
};

// Build the analyser graph. Must be called from a user gesture, after the
// audio element is already playing, so the freshly-resumed context stays
// "running" and audio keeps flowing through analyser → destination.
const setupAnalyser = async () => {
    if (analyser || !audio.value) return;
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        audioCtx = new Ctx();
        // Only wire up the graph once the context is actually running —
        // otherwise createMediaElementSource() would silence playback.
        await audioCtx.resume();
        if (audioCtx.state !== 'running') return;

        const source = audioCtx.createMediaElementSource(audio.value);
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 128; // 64 frequency bins — enough to cover BARS
        freqData = new Uint8Array(analyser.frequencyBinCount);
        source.connect(analyser).connect(audioCtx.destination);
    } catch (e) {
        // Analysis unavailable — fall back to the synthetic waveform below.
        analyser = null;
    }
};

const renderBars = () => {
    const bars = barRefs.value;
    if (!bars.length) {
        rafId = requestAnimationFrame(renderBars);
        return;
    }

    let heights;
    if (isPlaying.value && analyser) {
        // Real spectrum: map each bar to a frequency bin.
        analyser.getByteFrequencyData(freqData);
        heights = bars.map((_, i) => {
            const v = freqData[i] ?? 0;
            return 10 + (v / 255) * 320;
        });
    } else if (isPlaying.value) {
        // Analyser not up yet — synthetic waveform keeps the bars alive.
        synthPhase += 0.08;
        heights = bars.map((_, i) => {
            const wave = Math.sin(synthPhase + i * 0.5) * 0.5 + 0.5;
            const flick = Math.sin(synthPhase * 2.3 + i) * 0.3 + 0.5;
            return 10 + wave * flick * 180;
        });
    } else {
        heights = bars.map(() => 10);
    }

    for (let i = 0; i < bars.length; i++) {
        bars[i].style.height = `${heights[i]}px`;
    }
    rafId = requestAnimationFrame(renderBars);
};

// Start playback. The live Icecast stream is the single source of truth, so every
// client that plays is hearing the exact same audio — i.e. synced.
const startPlayback = async () => {
    if (!audio.value) return;
    // Try to start with sound. This succeeds in the join → player flow because the
    // "Join" click is a fresh user gesture that carries into this page.
    try {
        audio.value.muted = false;
        await audio.value.play();
        isPlaying.value = true;
        needsGesture.value = false;
        setupAnalyser();
    } catch (e) {
        // Autoplay with sound was refused (e.g. a hard refresh, no prior gesture).
        // Show the click-to-listen gate so the listener can start it explicitly.
        needsGesture.value = true;
    }
};

// Invoked by the click-to-listen gate — a guaranteed user gesture, so play() with
// sound is always allowed here.
const startListening = async () => {
    if (!audio.value || connecting.value) return;
    // Swap the gate to a loading state so the listener knows the click landed
    // and there's nothing to do but wait for the stream to buffer.
    connecting.value = true;
    try {
        audio.value.muted = false;
        await audio.value.play();
        isPlaying.value = true;
        needsGesture.value = false;
        setupAnalyser();
    } catch (e) {
        // Playback still refused — drop back to the click-to-listen state so the
        // user can try again.
        connecting.value = false;
    }
};

// ---- Upload -----------------------------------------------------------
const submitTrack = () => {
    uploadForm.post('/tracks', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            showUpload.value = false;
        },
    });
};

const onFile = (e) => {
    uploadForm.track = e.target.files[0] ?? null;
};

const onDrop = (e) => {
    dragOver.value = false;
    const f = e.dataTransfer?.files?.[0];
    if (f) uploadForm.track = f;
};

const clearFile = () => {
    uploadForm.track = null;
};

// Skip is a toggle: the first click casts a vote, a second click withdraws it.
// The live tally still arrives via the VoteCountUpdated broadcast, so we only
// flip our own "voted" flag optimistically here.
const voteSkip = () => {
    if (state.voted) {
        state.voted = false;
        router.delete('/skip', { preserveScroll: true, preserveState: true });
    } else {
        state.voted = true;
        router.post('/skip', {}, { preserveScroll: true, preserveState: true });
    }
};

// ---- Emoji reactions ---------------------------------------------------
// Clicking a reaction floats a "bubble" up from the bottom-right and posts to
// /reactions so every other listener floats the same bubble. We render our own
// click locally for instant feedback and skip the echo of our own broadcast
// (matched by user id) so it never doubles up.
const bubbles = ref([]);
let bubbleSeq = 0;

// Google's Noto animated emoji, keyed by codepoint. Spreading the string yields
// full code points (surrogate pairs combined), so "❤️" → "2764_fe0f", "😍" →
// "1f60d", matching Noto's asset paths exactly. Animated WebP loops on its own.
const notoUrl = (emoji) => {
    const code = [...emoji].map((c) => c.codePointAt(0).toString(16)).join('_');
    return `https://fonts.gstatic.com/s/e/notoemoji/latest/${code}/512.webp`;
};

const spawnBubble = (emoji, nickname, avatar) => {
    const id = ++bubbleSeq;
    // Random horizontal start offset + sideways drift + rotation so a burst of
    // identical emoji fans out instead of stacking on one line.
    bubbles.value.push({
        id,
        emoji,
        nickname,
        avatar,
        offset: Math.round(Math.random() * 120), // px from the right edge
        drift: Math.round((Math.random() - 0.5) * 120), // px sideways while rising
        rotate: Math.round((Math.random() - 0.5) * 40), // deg
        scale: 0.85 + Math.random() * 0.5,
    });
    // Drop it after the animation finishes (keep in sync with the CSS duration).
    setTimeout(() => {
        bubbles.value = bubbles.value.filter((b) => b.id !== id);
    }, 3600);
};

const sendReaction = (emoji) => {
    spawnBubble(emoji, guest.value?.nickname ?? '', guest.value?.avatar_path ?? null);
    if (window.axios) {
        window.axios.post('/reactions', { emoji }).catch(() => {});
    }
};

const leave = () => {
    router.post('/leave');
};

// ---- Realtime ----------------------------------------------------------
let channel = null;
let heartbeat = null;

onMounted(() => {
    nextTick(() => renderBars());

    // Auto-start playback as soon as the listener lands on the player.
    startPlayback();

    heartbeat = setInterval(() => {
        if (window.axios) window.axios.post('/heartbeat').catch(() => {});
    }, 15000);

    if (!window.Echo) return;
    channel = window.Echo.channel('station');

    channel.listen('.TrackChanged', (e) => {
        state.nowPlaying = e.nowPlaying;
        state.vote = e.voteStatus ?? { current: 0, required: 0 };
        // Votes are cleared server-side when a track leaves the deck.
        state.voted = false;
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
    channel.listen('.EmojiReaction', (e) => {
        // We already floated our own bubble locally on click.
        if (guest.value && e.user?.id === guest.value.id) return;
        spawnBubble(e.emoji, e.user?.nickname ?? '', e.user?.avatar_path ?? null);
    });
});

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
    if (heartbeat) clearInterval(heartbeat);
    if (audioCtx) audioCtx.close().catch(() => {});
    if (window.Echo) window.Echo.leave('station');
});
</script>

<template>
    <Head title="Live Player" />

    <!-- Animated gradient backdrop -->
    <div class="aurora" aria-hidden="true"></div>

    <!-- Top-right small header -->
    <div class="fixed top-4 left-0 right-0 z-20 flex items-center justify-between px-5">
        <div class="flex items-center gap-2 text-white drop-shadow">
            <span class="text-xl">🎧</span>
            <span class="font-semibold">Jamdio</span>
            <span class="text-xs text-white/70">· {{ guest?.nickname }}</span>
        </div>
        <button class="text-sm text-white/80 hover:text-rose-300 transition" @click="leave">Leave</button>
    </div>

    <div
        v-if="state.stationStatus !== 'on'"
        class="fixed top-14 left-1/2 -translate-x-1/2 z-20 rounded-full bg-amber-500/20 backdrop-blur px-4 py-1.5 text-amber-100 text-xs ring-1 ring-amber-300/40"
    >
        Station offline · playback paused
    </div>

    <!-- ===== Click-to-listen gate (shown when autoplay is blocked) ===== -->
    <Transition name="modal">
        <div v-if="needsGesture" class="listen-gate" :class="{ connecting }" @click="startListening">
            <div class="listen-card">
                <template v-if="!connecting">
                    <span class="listen-ico">▶</span>
                    <p class="listen-title">Click to listen</p>
                    <p class="listen-sub">Your browser blocked autoplay — tap anywhere to join the live stream</p>
                </template>
                <template v-else>
                    <span class="listen-spinner" aria-hidden="true"></span>
                    <p class="listen-title">Connecting…</p>
                    <p class="listen-sub">Tuning you into the live stream — just a moment</p>
                </template>
            </div>
        </div>
    </Transition>

    <!-- ===== Flying emoji reactions (float up from bottom-right) ===== -->
    <div class="reactions-layer" aria-hidden="true">
        <div
            v-for="b in bubbles"
            :key="b.id"
            class="bubble"
            :style="{
                '--offset': b.offset + 'px',
                '--drift': b.drift + 'px',
                '--rotate': b.rotate + 'deg',
                '--scale': b.scale,
            }"
        >
            <img :src="notoUrl(b.emoji)" :alt="b.emoji" class="bubble-emoji" />
            <span v-if="b.nickname" class="bubble-name">
                <img v-if="b.avatar" :src="b.avatar" class="bubble-ava" />
                <span v-else class="bubble-ava fallback">🙂</span>
                {{ b.nickname }}
            </span>
        </div>
    </div>

    <!-- ===== Online users (right side, avatars only, name on hover) ===== -->
    <div class="users-left">
        <!-- Reaction picker — sits above the online users -->
        <div class="react-bar">
            <button
                v-for="emoji in reactions"
                :key="emoji"
                type="button"
                class="react-btn"
                :aria-label="`React ${emoji}`"
                @click="sendReaction(emoji)"
            >
                <img :src="notoUrl(emoji)" :alt="emoji" class="react-img" />
            </button>
        </div>

        <p class="users-left-label">Online · {{ state.onlineUsers.length }}</p>
        <TransitionGroup name="list" tag="div" class="users-grid">
            <div v-for="u in state.onlineUsers" :key="u.id" class="ava-wrap">
                <img v-if="u.avatar_path" :src="u.avatar_path" class="ava" />
                <span v-else class="ava fallback">🙂</span>
                <span class="ava-name">{{ u.nickname }}</span>
            </div>
        </TransitionGroup>
    </div>

    <!-- ===== Full-screen equalizer (playground player) ===== -->
    <div class="equalizer">
        <div
            v-for="i in BARS"
            :key="i"
            :ref="(el) => setBarRef(el, i - 1)"
            class="vertical"
        ></div>
    </div>

    <!-- ===== Skip control (bottom center) — white icon + vote count ===== -->
    <div class="skip-dock">
        <button
            class="skip-btn"
            :class="{ voted: state.voted }"
            :disabled="!state.nowPlaying"
            :aria-label="state.voted ? 'Withdraw your skip vote' : 'Vote to skip'"
            :title="state.voted ? 'Withdraw your skip vote' : 'Vote to skip'"
            @click="voteSkip"
        >
            <!-- Voted: an ✕ makes the withdraw affordance explicit. -->
            <svg v-if="state.voted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" />
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M4 5l10 7-10 7V5z" />
                <rect x="16.5" y="5" width="3" height="14" rx="1" />
            </svg>
        </button>
        <span class="skip-count">{{ state.vote.current }}/{{ state.vote.required }} votes</span>

        <audio ref="audio" :src="station?.streamUrl" preload="none" crossorigin="anonymous"></audio>
    </div>

    <!-- ===== Submit FAB (bottom-left, white circle, icon only) ===== -->
    <button class="fab" aria-label="Submit a track" title="Submit a track" @click="showUpload = true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M12 5v14M5 12h14" />
        </svg>
    </button>

    <!-- ===== Bottom-left info cluster (next to FAB) ===== -->
    <div class="info">
        <div class="info-row">
            <!-- Current playing, very big font -->
            <div class="np">
                <p class="np-label">Now playing<span class="live" :class="{ on: isPlaying }"></span></p>
                <p class="np-title">{{ nowTitle }}</p>
                <p class="np-by">
                    <template v-if="state.nowPlaying?.uploaded_by">
                        <img
                            v-if="state.nowPlaying.uploaded_by_avatar"
                            :src="state.nowPlaying.uploaded_by_avatar"
                            class="by-avatar"
                        />
                        <span v-else class="by-avatar fallback">🙂</span>
                        <span>submitted by {{ state.nowPlaying.uploaded_by }}</span>
                    </template>
                    <span v-else>background music</span>
                </p>
            </div>

            <!-- Upcoming, smaller font — to the right of current -->
            <div class="up">
                <p class="up-label">Up next · {{ state.queue.length }}</p>
                <TransitionGroup name="list" tag="ol" class="up-list">
                    <li v-for="(t, i) in state.queue.slice(0, 4)" :key="t.queue_id" class="up-item">
                        <span class="up-idx">{{ i + 1 }}</span>
                        <span class="truncate">{{ t.title }}</span>
                        <span class="up-dur">{{ fmt(t.duration) }}</span>
                    </li>
                    <li v-if="!state.queue.length" key="empty" class="up-empty">Empty — fallback music playing.</li>
                </TransitionGroup>
            </div>
        </div>

    </div>

    <!-- ===== Upload modal ===== -->
    <Transition name="modal">
        <div v-if="showUpload" class="modal-overlay" @click.self="showUpload = false">
            <div class="modal">
                <div class="modal-head">
                    <p class="modal-title">Submit a track</p>
                    <button class="modal-close" @click="showUpload = false">✕</button>
                </div>

                <form class="space-y-4" @submit.prevent="submitTrack">
                    <input
                        v-model="uploadForm.title"
                        type="text"
                        placeholder="Title (optional)"
                        class="modal-input"
                    />

                    <label
                        class="dropzone"
                        :class="{ 'drag-over': dragOver, 'has-file': uploadForm.track }"
                        @dragover.prevent="dragOver = true"
                        @dragleave.prevent="dragOver = false"
                        @drop.prevent="onDrop"
                    >
                        <input type="file" accept="audio/mpeg,.mp3" class="hidden" @change="onFile" />
                        <template v-if="!uploadForm.track">
                            <span class="text-3xl mb-1 dz-icon">🎵</span>
                            <span class="text-sm text-white/80">Drop an MP3 or <span class="text-fuchsia-300 underline">browse</span></span>
                            <span class="text-xs text-white/40 mt-0.5">Joins the global queue for everyone</span>
                        </template>
                        <template v-else>
                            <span class="text-3xl mb-1">✅</span>
                            <span class="text-sm text-white truncate max-w-full px-4">{{ fileName }}</span>
                            <button type="button" class="text-xs text-rose-300 hover:text-rose-200 mt-1" @click.prevent="clearFile">remove</button>
                        </template>
                    </label>

                    <p v-if="uploadForm.errors.track" class="text-xs text-rose-300">{{ uploadForm.errors.track }}</p>

                    <div v-if="uploadForm.processing" class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full bg-fuchsia-500 transition-all" :style="{ width: (uploadForm.progress?.percentage ?? 0) + '%' }"></div>
                    </div>

                    <button
                        type="submit"
                        :disabled="uploadForm.processing || !uploadForm.track"
                        class="modal-submit"
                    >
                        {{ uploadForm.processing ? 'Uploading…' : 'Add to queue' }}
                    </button>
                </form>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
/* Animated gradient backdrop (playground) */
.aurora {
    position: fixed;
    inset: 0;
    z-index: -1;
    background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
    background-size: 400% 400%;
    animation: aurora 18s ease infinite;
}
@keyframes aurora {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* ===== Equalizer (playground) ===== */
.equalizer {
    position: fixed;
    inset: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    padding: 0 4vw;
}
.vertical {
    height: 10px;
    width: 0.5vw;
    margin: 0.6vw;
    background-color: #3d143d;
    border-radius: 5px;
    transition: height 0.06s ease-in-out;
    will-change: height;
}

/* ===== Bottom-left info cluster ===== */
.info {
    position: fixed;
    left: 6rem; /* sits to the right of the FAB */
    bottom: 1.25rem;
    z-index: 24;
    /* Stop well short of the centered skip dock so a long title never
       collides with it, even on narrow laptops. */
    max-width: min(calc(50vw - 12rem), 60rem);
    max-height: calc(100vh - 8rem);
    display: flex;
    flex-direction: column;
    gap: 1rem;
    overflow: hidden;
    text-shadow: 0 2px 14px rgba(0, 0, 0, 0.5);
}
.info-row {
    display: flex;
    align-items: flex-end;
    gap: 2rem;
}
.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Now playing — very big font */
.np { min-width: 0; }
.np-label {
    font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.2em;
    color: rgba(255, 255, 255, 0.7);
}
.np-title {
    font-size: clamp(2.2rem, 5vw, 4rem);
    line-height: 1.02; font-weight: 800; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin: 0.1rem 0;
}
.np-by {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 1rem; color: rgba(255, 255, 255, 0.85);
}
.by-avatar {
    width: 1.7rem; height: 1.7rem; border-radius: 9999px;
    object-fit: cover; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.4);
}
.by-avatar.fallback { display: grid; place-items: center; background: rgba(255, 255, 255, 0.12); font-size: 0.85rem; }

/* Upcoming — smaller font */
.up { flex: 0 0 auto; min-width: 12rem; max-width: 18rem; padding-bottom: 0.4rem; }
.up-label, .ppl-label {
    font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.15em;
    color: rgba(255, 255, 255, 0.6); margin-bottom: 0.35rem;
}
.up-list { display: flex; flex-direction: column; gap: 0.15rem; }
.up-item {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.85rem; color: rgba(255, 255, 255, 0.85);
}
.up-idx { color: rgba(255, 255, 255, 0.4); font-variant-numeric: tabular-nums; width: 1rem; }
.up-dur { margin-left: auto; font-size: 0.7rem; color: rgba(255, 255, 255, 0.45); font-variant-numeric: tabular-nums; }
.up-empty { font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); }

/* ===== Online users (bottom-right, same row as dock/info) ===== */
.users-left {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 22;
    text-align: right;
    text-shadow: 0 2px 14px rgba(0, 0, 0, 0.5);
}
.users-left-label {
    font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.15em;
    color: rgba(255, 255, 255, 0.7); margin-bottom: 0.5rem;
}

/* ===== Reaction picker (above the online users) ===== */
.react-bar {
    display: inline-flex;
    gap: 0.25rem;
    margin-bottom: 0.75rem;
    padding: 0.3rem;
    border-radius: 9999px;
    background: rgba(0, 0, 0, 0.28);
    backdrop-filter: blur(6px);
    box-shadow: 0 6px 18px -6px rgba(0, 0, 0, 0.5);
}
.react-btn {
    display: grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    font-size: 1.15rem;
    line-height: 1;
    border: none;
    border-radius: 9999px;
    background: transparent;
    cursor: pointer;
    transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.15s ease;
}
.react-btn:hover { transform: scale(1.35) translateY(-2px); background: rgba(255, 255, 255, 0.12); }
.react-btn:active { transform: scale(1.05); }
.react-img { width: 1.5rem; height: 1.5rem; display: block; }

/* ===== Flying emoji bubbles ===== */
.reactions-layer {
    position: fixed;
    right: 0;
    bottom: 0;
    width: min(40vw, 22rem);
    height: 70vh;
    z-index: 40;
    pointer-events: none;
    overflow: hidden;
}
.bubble {
    position: absolute;
    right: var(--offset, 40px);
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.2rem;
    will-change: transform, opacity;
    animation: bubble-rise 3.6s cubic-bezier(0.22, 0.61, 0.36, 1) forwards;
}
.bubble-emoji {
    display: block;
    width: 3rem;
    height: 3rem;
    filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.45));
}
.bubble-name {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    max-width: 8rem;
    padding: 0.05rem 0.4rem 0.05rem 0.1rem;
    border-radius: 9999px;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    font-size: 0.62rem;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
}
.bubble-ava {
    flex: none;
    width: 0.85rem;
    height: 0.85rem;
    border-radius: 9999px;
    object-fit: cover;
    box-shadow: 0 0 0 1.5px rgba(255, 255, 255, 0.8);
}
.bubble-ava.fallback {
    display: grid; place-items: center;
    background: rgba(31, 12, 33, 0.9); font-size: 0.5rem;
}
@keyframes bubble-rise {
    0% {
        opacity: 0;
        transform: translate(0, 0) scale(calc(var(--scale, 1) * 0.4)) rotate(0deg);
    }
    12% {
        opacity: 1;
        transform: translate(calc(var(--drift) * 0.15), -8vh) scale(var(--scale, 1)) rotate(calc(var(--rotate) * 0.2));
    }
    78% {
        opacity: 1;
        transform: translate(calc(var(--drift) * 0.85), -52vh) scale(var(--scale, 1)) rotate(calc(var(--rotate) * 0.85));
    }
    100% {
        opacity: 0;
        transform: translate(var(--drift), -65vh) scale(calc(var(--scale, 1) * 0.9)) rotate(var(--rotate));
    }
}
@media (prefers-reduced-motion: reduce) {
    .bubble { animation-duration: 1.2s; }
}
.users-grid {
    display: grid;
    direction: rtl;
    grid-template-columns: repeat(8, auto); /* 8 avatars per row, then wrap */
    gap: 0.5rem;
    justify-content: start;
}
.ava-wrap { position: relative; line-height: 0; }
.ava {
    width: 2.4rem; height: 2.4rem; border-radius: 9999px;
    object-fit: cover; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.4);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.ava.fallback {
    display: grid; place-items: center; background: rgba(255, 255, 255, 0.12); font-size: 1.1rem;
}
.ava-wrap:hover .ava { transform: scale(1.12); box-shadow: 0 0 0 2px #b651b8; }
.ava-name {
    position: absolute;
    bottom: calc(100% + 0.4rem);
    left: 50%;
    transform: translateX(-50%) translateY(4px);
    background: rgba(0, 0, 0, 0.85);
    color: #fff; font-size: 0.7rem; line-height: 1.2;
    padding: 0.2rem 0.5rem; border-radius: 0.4rem;
    white-space: nowrap; pointer-events: none;
    opacity: 0; transition: opacity 0.15s ease, transform 0.15s ease;
    z-index: 30;
}
.ava-wrap:hover .ava-name { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ===== Skip control (bottom center) ===== */
.skip-dock {
    position: fixed;
    left: 50%;
    bottom: 1.25rem;
    transform: translateX(-50%);
    z-index: 20;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.skip-btn {
    display: grid;
    place-items: center;
    width: 3rem;
    height: 3rem;
    color: #fff;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: transform 0.18s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.2s ease;
    filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.5));
}
.skip-btn svg { width: 1.9rem; height: 1.9rem; }
.skip-btn:hover {
    transform: scale(1.25) translateX(3px);
    filter: drop-shadow(0 0 12px rgba(255, 255, 255, 0.85));
}
.skip-btn:active { transform: scale(1.05) translateX(2px); }
.skip-btn:disabled { opacity: 0.35; cursor: not-allowed; transform: none; filter: none; }
/* Voted: filled accent pill reading as "you voted — click to withdraw". */
.skip-btn.voted {
    background: linear-gradient(135deg, #b651b8, #e73c7e);
    border-radius: 9999px;
    box-shadow: 0 6px 18px -6px rgba(231, 60, 126, 0.7);
}
.skip-btn.voted svg { width: 1.5rem; height: 1.5rem; }
.skip-btn.voted:hover {
    transform: scale(1.12);
    filter: brightness(1.1) drop-shadow(0 0 10px rgba(231, 60, 126, 0.6));
}
.skip-count {
    font-size: 0.9rem;
    font-weight: 600;
    color: #fff;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
    font-variant-numeric: tabular-nums;
}

/* Click-to-listen gate */
.listen-gate {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(6px);
    cursor: pointer;
}
.listen-gate.connecting { cursor: default; }
.listen-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    padding: 2.5rem 3rem;
    border-radius: 1.5rem;
    background: rgba(31, 12, 33, 0.85);
    border: 1px solid rgba(255, 255, 255, 0.18);
    box-shadow: 0 30px 80px -20px rgba(0, 0, 0, 0.8);
    text-align: center;
    animation: gate-pop 0.3s ease;
}
.listen-ico {
    display: grid;
    place-items: center;
    width: 5rem;
    height: 5rem;
    margin-bottom: 0.5rem;
    border-radius: 9999px;
    background: linear-gradient(135deg, #b651b8, #e73c7e);
    color: #fff;
    font-size: 2rem;
    padding-left: 0.35rem; /* optically center the ▶ glyph */
    box-shadow: 0 12px 30px -8px rgba(231, 60, 126, 0.7);
    animation: gate-pulse 1.8s ease-in-out infinite;
}
.listen-spinner {
    display: block;
    width: 5rem;
    height: 5rem;
    margin-bottom: 0.5rem;
    border-radius: 9999px;
    border: 4px solid rgba(255, 255, 255, 0.18);
    border-top-color: #e73c7e;
    animation: listen-spin 0.8s linear infinite;
}
@keyframes listen-spin {
    to { transform: rotate(360deg); }
}
.listen-title { font-size: 1.5rem; font-weight: 800; color: #fff; }
.listen-sub { font-size: 0.9rem; color: rgba(255, 255, 255, 0.7); max-width: 18rem; }
@keyframes gate-pop {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
}
@keyframes gate-pulse {
    0%, 100% { box-shadow: 0 12px 30px -8px rgba(231, 60, 126, 0.7), 0 0 0 0 rgba(231, 60, 126, 0.5); }
    50% { box-shadow: 0 12px 30px -8px rgba(231, 60, 126, 0.7), 0 0 0 18px rgba(231, 60, 126, 0); }
}

/* Live dot */
.live {
    display: inline-block; width: 6px; height: 6px; border-radius: 9999px;
    background: rgba(255, 255, 255, 0.4); margin-left: 6px; vertical-align: middle;
}
.live.on { background: #34d399; animation: blink 1.4s ease-in-out infinite; }
@keyframes blink {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.6); }
    50% { opacity: 0.5; box-shadow: 0 0 0 5px rgba(52, 211, 153, 0); }
}


/* ===== Submit FAB (bottom-left) ===== */
.fab {
    position: fixed;
    left: 1.25rem;
    bottom: 1.25rem;
    z-index: 25;
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 9999px;
    display: grid;
    place-items: center;
    background: #fff;
    color: #3d143d;
    box-shadow: 0 10px 28px -6px rgba(0, 0, 0, 0.45);
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.25s ease;
}
.fab svg { width: 1.6rem; height: 1.6rem; }
.fab:hover { transform: scale(1.08) rotate(90deg); box-shadow: 0 12px 32px -4px rgba(182, 81, 184, 0.6); }
.fab:active { transform: scale(0.94); }

/* ===== Modal ===== */
.modal-overlay {
    position: fixed; inset: 0; z-index: 50;
    display: grid; place-items: center; padding: 1rem;
    background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(4px);
}
.modal {
    width: 100%; max-width: 26rem;
    background: rgba(31, 12, 33, 0.92);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 1.25rem; padding: 1.5rem;
    box-shadow: 0 24px 60px -12px rgba(0, 0, 0, 0.7);
}
.modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.modal-title { font-size: 1.1rem; font-weight: 700; color: #fff; }
.modal-close { color: rgba(255, 255, 255, 0.6); font-size: 1rem; cursor: pointer; }
.modal-close:hover { color: #fff; }
.modal-input {
    width: 100%; border-radius: 0.75rem; background: rgba(255, 255, 255, 0.06);
    padding: 0.6rem 0.75rem; font-size: 0.9rem; color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.15); outline: none;
}
.modal-input::placeholder { color: rgba(255, 255, 255, 0.4); }
.modal-input:focus { border-color: #b651b8; }
.modal-submit {
    width: 100%; border-radius: 0.75rem; padding: 0.7rem;
    font-size: 0.9rem; font-weight: 700; color: #fff;
    background: linear-gradient(135deg, #b651b8, #e73c7e);
    box-shadow: 0 8px 22px -6px rgba(231, 60, 126, 0.5);
    cursor: pointer; transition: filter 0.2s ease, transform 0.1s ease;
}
.modal-submit:hover:not(:disabled) { filter: brightness(1.1); }
.modal-submit:active:not(:disabled) { transform: scale(0.97); }
.modal-submit:disabled { opacity: 0.5; cursor: not-allowed; }

/* Dropzone */
.dropzone {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; min-height: 130px; padding: 1.25rem; border-radius: 1rem;
    border: 2px dashed rgba(255, 255, 255, 0.25); background: rgba(255, 255, 255, 0.03);
    cursor: pointer; transition: all 0.2s ease;
}
.dropzone:hover { border-color: rgba(182, 81, 184, 0.7); background: rgba(182, 81, 184, 0.07); }
.dropzone.drag-over { border-color: #b651b8; background: rgba(182, 81, 184, 0.15); transform: scale(1.01); }
.dropzone.has-file { border-style: solid; border-color: rgba(52, 211, 153, 0.5); }
.dz-icon { animation: float 3s ease-in-out infinite; }
@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

/* Transitions */
.modal-enter-active, .modal-leave-active { transition: opacity 0.25s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-active .modal, .modal-leave-active .modal { transition: transform 0.25s ease; }
.modal-enter-from .modal, .modal-leave-to .modal { transform: scale(0.92) translateY(10px); }

.list-enter-active, .list-leave-active { transition: all 0.3s ease; }
.list-enter-from { opacity: 0; transform: translateX(-12px); }
.list-leave-to { opacity: 0; transform: translateX(12px); }
.list-move { transition: transform 0.3s ease; }

/* ===== Responsive ==========================================================
   The desktop layout scatters UI into the four corners around a full-screen
   equalizer. That falls apart on smaller screens, so from tablet down we lift
   everything into two unambiguous zones that can't collide:

     · a top RAIL   — reactions + who's online (horizontal, scrolls)
     · a bottom STACK — now-playing → up-next → the control bar

   The equalizer stays as full-screen ambient texture behind both (it sits below
   every interactive element in the z-order), and all floor-anchored controls
   respect the iOS safe-area inset. ------------------------------------------- */
@media (max-width: 1024px) {
    /* Fewer, chunkier bars read better as background texture on small screens. */
    .equalizer { padding: 0 3vw; }
    .vertical { width: 1vw; margin: 0.55vw; }

    /* People + reactions collapse into one horizontal rail under the header. */
    .users-left {
        top: calc(env(safe-area-inset-top, 0px) + 3rem);
        right: 0; left: 0; bottom: auto;
        display: flex; align-items: center; gap: 0.6rem;
        padding: 0.5rem 1rem 0.7rem;
        text-align: left;
        background: linear-gradient(to bottom, rgba(0, 0, 0, 0.45), rgba(0, 0, 0, 0));
    }
    .react-bar { margin: 0; flex: none; }
    .users-left-label { margin: 0 0.25rem 0 0; flex: none; white-space: nowrap; }
    .users-grid {
        grid-auto-flow: column;
        grid-auto-columns: max-content;
        grid-template-columns: none;
        direction: ltr;
        flex: 1; min-width: 0;
        overflow-x: auto;
        padding-bottom: 0.15rem;
        scrollbar-width: none;
    }
    .users-grid::-webkit-scrollbar { display: none; }
    /* The name tooltip would clip inside the horizontal scroller — flip below. */
    .ava-name { bottom: auto; top: calc(100% + 0.35rem); }
    .ava-wrap:hover .ava-name { transform: translateX(-50%) translateY(0); }

    /* Now-playing + up-next span the full width, just above the control bar. */
    .info {
        left: 0; right: 0;
        bottom: calc(env(safe-area-inset-bottom, 0px) + 5rem);
        max-width: none; max-height: 40vh;
        padding: 0 1.25rem;
        overflow: hidden;
    }

    /* Floor controls ride on the safe-area inset. */
    .fab { left: 1rem; bottom: calc(env(safe-area-inset-bottom, 0px) + 1rem); }
    .skip-dock { bottom: calc(env(safe-area-inset-bottom, 0px) + 1rem); }
}

/* Tablet: enough width to keep now-playing beside the up-next list. */
@media (min-width: 768px) and (max-width: 1024px) {
    .info-row { align-items: flex-end; gap: 2rem; }
    .np-title { font-size: clamp(2rem, 4.5vw, 3.2rem); }
    .up { min-width: 13rem; max-width: 20rem; }
}

/* Phones: stack now-playing over a horizontal up-next chip row. */
@media (max-width: 767px) {
    .info-row { flex-direction: column; align-items: stretch; gap: 0.75rem; }
    .np-title {
        font-size: clamp(1.7rem, 7.5vw, 2.6rem);
        line-height: 1.08;
        white-space: normal;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    }
    .np-by { font-size: 0.85rem; }

    /* Up-next becomes a swipeable row of pills instead of a vertical list. */
    .up { min-width: 0; max-width: none; padding-bottom: 0; }
    .up-list {
        flex-direction: row; gap: 0.4rem;
        overflow-x: auto; padding-bottom: 0.15rem;
        scrollbar-width: none;
    }
    .up-list::-webkit-scrollbar { display: none; }
    .up-idx { display: none; }
    .up-item {
        flex: 0 0 auto; gap: 0.4rem;
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        background: rgba(0, 0, 0, 0.32);
    }
    .up-item .truncate { max-width: 8rem; }
    .up-dur { margin-left: 0.35rem; }

    /* Touch-friendly control sizes. */
    .fab { width: 3.25rem; height: 3.25rem; }
    .skip-btn { width: 3.25rem; height: 3.25rem; }
    .skip-count { font-size: 0.85rem; }

    /* Reclaim rail space — the count is already on each avatar via hover/tap. */
    .users-left-label { display: none; }
    .ava { width: 2rem; height: 2rem; }
}

/* Small phones: shave everything down a notch. */
@media (max-width: 380px) {
    .np-title { font-size: clamp(1.5rem, 8vw, 2rem); }
    .react-btn { width: 1.9rem; height: 1.9rem; }
    .react-img { width: 1.35rem; height: 1.35rem; }
    .ava { width: 1.8rem; height: 1.8rem; }
    .info { bottom: calc(env(safe-area-inset-bottom, 0px) + 4.75rem); }
}

/* Landscape phones: almost no vertical room — compress the stack. */
@media (max-height: 500px) and (orientation: landscape) {
    .users-left { padding-top: 0.35rem; padding-bottom: 0.4rem; }
    .info {
        max-height: 34vh;
        bottom: calc(env(safe-area-inset-bottom, 0px) + 4rem);
    }
    .info-row { flex-direction: row; align-items: flex-end; gap: 1.5rem; }
    .np-title { font-size: clamp(1.4rem, 5vw, 2rem); -webkit-line-clamp: 1; }
    .up { max-width: 18rem; }
}
</style>
