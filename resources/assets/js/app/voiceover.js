'use strict';

import Alpine from 'alpinejs';
import api from './api';

export function voiceover() {
    Alpine.data('voiceover', (voice = null, speech = null) => ({
        isProcessing: false,
        isDeleting: false,
        history: null,
        preview: speech,
        showSettings: false,
        voice: voice,
        prompt: null,
        query: '',

        voices: null,
        isLoading: false,
        hasMore: true,
        currentResource: null,
        showList: false,

        init() {
            this.$watch('preview', (value) => {
                // Update the item in the history list
                if (this.history && value) {
                    let index = this.history.findIndex(item => item.id === value.id);
                    if (index >= 0) {
                        this.history[index] = value;
                    }
                }
            });

            this.fetchHistory();
            this.getVoices();
        },

        fetchHistory() {
            api.get('/library/speeches')
                .then(response => response.json())
                .then(list => {
                    let data = list.data;
                    this.history = data.reverse();
                });
        },

        getVoices(cursor = null, reset = false) {
            if (reset) {
                this.isLoading = false;
                this.hasMore = true;
            }

            if (
                !this.hasMore
                || this.isLoading
            ) {
                return;
            }

            this.isLoading = true;
            let params = {
                limit: 25
            };

            if (cursor) {
                params.starting_after = cursor;
            }

            if (this.query) {
                params.query = this.query;
            }

            api.get('/voices', params)
                .then(response => response.json())
                .then(list => {
                    this.isLoading = false;

                    if (!this.voices) {
                        this.voices = [];
                    }

                    reset ? this.voices = list.data : this.voices.push(...list.data);
                    this.hasMore = list.data.length >= params.limit;
                });
        },

        submit() {
            if (this.isProcessing) {
                return;
            }

            this.isProcessing = true;

            let data = {
                voice_id: this.voice.id,
                prompt: this.prompt,
            };

            api.post('/ai/speeches', data)
                .then(response => response.json())
                .then((speech) => {
                    if (this.history === null) {
                        this.history = [];
                    }

                    this.history.push(speech);
                    this.preview = speech;
                    this.isProcessing = false;
                    this.prompt = null;

                    this.select(speech);
                })
                .catch(error => {
                    this.isProcessing = false;
                    console.error(error);
                });
        },

        select(speech) {
            this.preview = speech;

            let url = new URL(window.location.href);
            url.pathname = '/app/voiceover/' + speech.id;
            window.history.pushState({}, '', url);

            if (speech.voice) {
                this.voice = speech.voice;
            }
        },

        save(speech) {
            api.post(`/library/speeches/${speech.id}`, {
                title: speech.title,
            });
        },

        remove(transcription) {
            this.isDeleting = true;

            api.delete(`/library/speeches/${transcription.id}`)
                .then(() => {
                    this.preview = null;
                    window.modal.close();

                    toast.show("Speech has been deleted successfully.", 'ti ti-trash');
                    this.isDeleting = false;

                    let url = new URL(window.location.href);
                    url.pathname = '/app/voiceover/' + (this.voice?.id || this.voices[0] || '');
                    window.history.pushState({}, '', url);

                    this.history.splice(this.history.indexOf(transcription), 1);
                })
                .catch(error => this.isDeleting = false);
        },

        selectVoice(voice) {
            this.voice = voice;
            window.modal.close();

            let url = new URL(window.location.href);
            url.pathname = '/app/voiceover/' + voice.id;
            window.history.pushState({}, '', url);
        }
    }));

    Alpine.data('audience', (item) => ({
        item: item,
        isProcessing: null,

        changeAudience(visibility) {
            this.isProcessing = visibility;

            api.post(`/library/${this.item.id}`, { visibility: visibility })
                .then(resp => resp.json())
                .then(resp => {
                    window.modal.close();

                    this.isProcessing = null;
                    this.item = resp;
                });
        }
    }));
}