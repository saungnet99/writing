'use strict';

import WaveSurfer from "wavesurfer.js";
import WebAudioPlayer from 'wavesurfer.js/dist/webaudio.js'

/**
 * Usage: 
 * 
 * <x-wave src="https://example.com/audio.mp3">
 * </x-wave>
 * 
 * <x-wave src="https://example.com/audio.mp3">
 *     <button type="button" play-pause></button>
 *     <button type="button" play></button>
 *     <button type="button" play></button>
 * 
 *     <div wave></div>
 *     <span progress></span>
 * </x-wave>
 */

export class WaveElement extends HTMLElement {
    static nowPlaying = null;

    static observedAttributes = [
        'src',
        'wave-color',
        'progress-color',
        'cursor-color',
        'lazy'
    ];

    constructor() {
        super();

        this.isRendered = false;

        this.container = this.querySelector('[wave]') || this;
        this.playBtn = this.querySelector('button[player]') || null;
        this.pauseBtn = this.querySelector('button[pause]') || null;
        this.playPauseBtn = this.querySelector('button[play-pause]') || null;
        this.processEl = this.querySelector('[process]') || null;
        this.durationEl = this.querySelector('[duration]') || null;

        if (this.playBtn) {
            this.playBtn.addEventListener('click', () => {
                if (!this.isRendered) {
                    this.render();
                    return;
                }

                this.wave.play();
            });
        }

        if (this.pauseBtn) {
            this.pauseBtn.addEventListener('click', () => {
                this.wave.pause();
            });
        }

        if (this.playPauseBtn) {
            this.playPauseBtn.addEventListener('click', () => {
                if (!this.isRendered) {
                    this.render();
                    return;
                }

                this.wave.playPause();
            });
        }
    }

    connectedCallback() {
        // this.render();
    }

    disconnectedCallback() {
        if (this.wave) {
            this.wave.destroy();
            WaveElement.nowPlaying = null;
        }
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (this.hasAttribute('lazy') && !this.isRendered) {
            return;
        }

        this.render();
    }

    getReadableDuration(duration) {
        let date = new Date(0);
        date.setSeconds(duration);

        if (duration > 3600) {
            return date.toISOString().substring(11, 19)
        }

        return date.toISOString().substring(14, 19)
    }

    seekTo(duration = 0) {
        this.wave.seekTo(duration / this.wave.getDuration());
    }

    render() {
        if (this.wave) {
            this.wave.destroy();
        }

        const audio = new WebAudioPlayer()
        audio.src = this.getAttribute('src');

        this.wave = WaveSurfer.create({
            container: this.container,
            height: 'auto',
            waveColor: this.getAttribute('wave-color') || `rgb(${getComputedStyle(document.documentElement).getPropertyValue('--color-line')})`,
            progressColor: this.getAttribute('progress-color') || `rgb(${getComputedStyle(document.documentElement).getPropertyValue('--color-content')})`,
            cursorColor: this.getAttribute('cursor-color') || `rgb(${getComputedStyle(document.documentElement).getPropertyValue('--color-content')})`,
            barWidth: 2,
            normalize: true,
            cursorWidth: 0,
            barGap: 0,
            barRadius: 30,
            dragToSeek: true,
            media: audio,
            // url: this.getAttribute('src'),
        });

        // this.wave.load(this.getAttribute('src'));
        this.wave.on("loading", () => this.setAttribute('state', "loading"));
        this.wave.on("load", () => this.setAttribute('state', "loaded"));
        this.wave.on("ready", (duration) => {
            this.setAttribute('state', "ready")

            if (this.durationEl) {
                this.durationEl.innerText = this.getReadableDuration(duration);
            }

            if (this.hasAttribute('lazy')) {
                this.wave.play();
            }
        });

        this.wave.on('interaction', () => {
            if (!this.wave.isPlaying()) {
                this.wave.play();
            }
        });

        this.wave.on('audioprocess', (time) => {
            let duration = this.getReadableDuration(time);

            this.setAttribute('process', duration);

            // Dispatch/Trigger/Fire the event
            this.dispatchEvent(new CustomEvent(
                "audioprocess",
                { detail: { time: time } },
            ));

            if (this.processEl) {
                this.processEl.innerText = duration;
            }
        });

        this.wave.on('play', async () => {
            if (WaveElement.nowPlaying && WaveElement.nowPlaying != this.wave) {
                await WaveElement.nowPlaying.pause();
            }

            WaveElement.nowPlaying = this.wave;
            this.setAttribute('state', "playing");
        });

        this.wave.on('pause', () => {
            this.setAttribute('state', "paused");
        });

        this.isRendered = true;
    }
}