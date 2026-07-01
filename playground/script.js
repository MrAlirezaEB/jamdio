function updateEqualizer(analyser, buffer, columns) {
  analyser.getByteFrequencyData(buffer);
  for (let i = 0; i < columns.length; i += 1) {
    let column = columns[i];
    const height = (buffer[i] / 255) * 400 + 10;
    column.setAttribute("style", `height: ${height}px`);
  }
}

function toMinutesAndSeconds(time) {
  const minutes = Math.floor(time / 60);
  const seconds = Math.floor(time % 60);
  if (seconds < 10) {
    return `${minutes}:0${seconds}`;
  }

  return `${minutes}:${seconds}`;
}

function updateProgressBar(track, progressBar, timeDisplay, durationDisplay) {
  const currentTime = track.mediaElement.currentTime.toFixed(1);
  const duration = track.mediaElement.duration;
  timeDisplay.innerHTML = toMinutesAndSeconds(
    currentTime > duration ? duration : currentTime
  );
  progressBar.value = currentTime > duration ? duration : currentTime;
}
console.clear();

// instigate our audio context
let audioElement = new Audio();
audioElement.crossOrigin = "anonymous";

let lastFetchedTime = 0; // Store the last valid buffered time.

// Track buffered data in the stream
function trackBufferedData() {
  if (audioElement.buffered.length > 0) {
    // Get the last buffered time
    const bufferedEnd = audioElement.buffered.end(audioElement.buffered.length - 1);
    lastFetchedTime = bufferedEnd; // Update to the last valid buffer end point
  }
}

setInterval(trackBufferedData, 100);

// audioElement.addEventListener(
//   "loadedmetadata",
//   (event) => {
    
//   },
//   false
// );

// for cross browser
const AudioContext = window.AudioContext || window.webkitAudioContext;
const audioCtx = new AudioContext();

// load some sound
const track = audioCtx.createMediaElementSource(audioElement);
const playButton = document.querySelector("button");
const body = document.querySelector("body");
let intracted = false;

body.addEventListener(
  "click",
  function() {
    playButton.click();
  }
)

// play pause audio
playButton.addEventListener(
  "click",
  function () {
    if(!intracted) {
      audioElement.src = "http://localhost:8001/stream";
      intracted = true;
    }
    // check if context is in suspended state (autoplay policy)
    if (audioCtx.state === "suspended") {
      audioCtx.resume();
    }

    if (this.dataset.playing === "false") {
      audioElement.play();
      this.dataset.playing = "true";
      // if track is playing pause it
    } else if (this.dataset.playing === "true") {
      audioElement.pause();
      this.dataset.playing = "false";
    }

    let state = this.getAttribute("aria-checked") === "true" ? true : false;
    this.setAttribute("aria-checked", state ? "false" : "true");
  },
  false
);

// if track ends
audioElement.addEventListener(
  "ended",
  () => {
    playButton.dataset.playing = "false";
    playButton.setAttribute("aria-checked", "false");
  },
  false
);

// initialize gain
let gainNode = audioCtx.createGain();

// initialize analyser
let analyser = audioCtx.createAnalyser();
const columns = document.getElementsByClassName("vertical");
analyser.fftSize = 128; //32 bins
const frequencyBinsCount = analyser.frequencyBinCount;
let buffer = new Uint8Array(frequencyBinsCount);
track.connect(gainNode).connect(analyser).connect(audioCtx.destination);

// equalizer animation
const equalizer = document.getElementById("equalizer");
// 32 bins -> 32 columns
setInterval(updateEqualizer, 16, analyser, buffer, columns);

const currentTimeDisplay = document.querySelector(".current-time");
const progressBar = document.querySelector(".progress-bar");
const durationDisplay = document.querySelector(".duration");
durationDisplay.innerHTML = toMinutesAndSeconds(
  track.mediaElement.duration
);
progressBar.max = track.mediaElement.duration;

setInterval(
  updateProgressBar,
  100,
  track,
  progressBar,
  currentTimeDisplay,
  durationDisplay
);

const volumeControl = document.querySelector("#range-volume");

volumeControl.addEventListener(
  "input",
  function () {
    gainNode.gain.value = this.value;
  },
  false
);

// mute button
const mute_button = document.getElementById("mute");
mute_button.addEventListener(
  "change",
  (event) => {
    if (event.target.checked) {
      audioElement.muted = true;
    } else {
      audioElement.muted = false;
    }
  },
  false
);

// When user pauses the audio, reset the time to the last fetched data
audioElement.addEventListener('pause', () => {
  audioElement.currentTime = lastFetchedTime; // Start from the last valid buffered point
});

// Optional: Ensure the audio resumes from the correct point
audioElement.addEventListener('play', () => {
  audioElement.currentTime = lastFetchedTime; // Start from the last valid buffered point
});