'use strict';

import Alpine from 'alpinejs';
import api from './api';
import { toast } from '../base/toast';

export function imagineView() {
    Alpine.data('imagine', (model, adapters = [], samples = [], image = null) => ({
        samples: samples,
        adapters: [],
        adapter: null,

        showSettings: false,
        history: null,
        isProcessing: false,
        isDeleting: false,
        preview: null,

        prompt: null,
        negativePrompt: null,
        ratio: null,

        params: {
            width: null,
            height: null,
        },

        original: {},

        placeholder: null,
        timer: 0,

        init() {
            adapters.forEach(adapter => {
                if (adapter.is_available) {
                    adapter.models.forEach(model => {
                        if (model.is_available) {
                            this.adapters.push(model);
                        }
                    });
                }
            });

            this.$watch('preview', (value) => {
                // Update the item in the history list
                if (this.history && value) {
                    let index = this.history.findIndex(item => item.id === value.id);
                    if (index >= 0) {
                        this.history[index] = value;
                    }
                }
            });

            api.get('/library/images')
                .then(response => response.json())
                .then(list => {
                    let data = list.data;
                    this.history = data.reverse();
                })

            this.original = { ...this.params };

            this.adapter = this.adapters.find(adapter => adapter.model == model);
            if (!this.adapter && this.adapters.length > 0) {
                this.adapter = this.adapters[0];
            }

            this.$watch('adapter', () => this.reset());

            if (image) {
                this.select(image);
            }
        },

        reset() {
            for (let key in this.params) {
                if (this.original[key] === undefined) {
                    delete this.params[key];
                    continue;
                }

                this.params[key] = this.original[key];
            }
        },

        typeWrite(field, value) {
            let i = 0;
            let speed = 10;

            let typeWriter = () => {
                if (i < value.length) {
                    this[field] += value.charAt(i);
                    i++;

                    clearTimeout(this.timer);
                    this.timer = setTimeout(typeWriter, speed);
                }
            };

            this[field] = '';
            typeWriter();
        },

        surprise() {
            let prompt = this.samples[Math.floor(Math.random() * this.samples.length)];
            this.$refs.prompt.focus();
            this.typeWrite('prompt', prompt);
        },

        placeholderSurprise() {
            clearTimeout(this.timer);

            if (this.prompt) {
                return;
            }

            this.timer = setTimeout(() => {
                let randomPrompt = this.samples[Math.floor(Math.random() * this.samples.length)];
                this.typeWrite('placeholder', randomPrompt);
            }, 2000);
        },

        tab(e) {
            if (this.prompt != this.placeholder && this.placeholder) {
                e.preventDefault();
                this.prompt = this.placeholder;
            }
        },

        blur() {
            this.placeholder = null;
            clearTimeout(this.timer);
        },

        submit($el) {
            if (this.isProcessing) {
                return;
            }

            this.isProcessing = true;

            let data = {
                ...this.params,
                prompt: this.prompt,
                negative_prompt: this.negativePrompt,
                model: this.adapter.model || null,
            };

            if (data.aspect_ratio) {
                this.setRatio(data.aspect_ratio);
            } else {
                if (!data.width || !data.height) {
                    let size = this.adapter.sizes[0] || { width: 640, height: 400 };

                    data.width = size.width;
                    data.height = size.height;
                }

                this.setRatio(data.width + ':' + data.height);
            }

            api.post(`/ai/images`, data)
                .then(response => response.json())
                .then(image => {
                    this.history.push(image);
                    this.select(image);
                    this.prompt = null;
                    this.isProcessing = false;
                })
                .catch(error => {
                    this.isProcessing = false;
                    this.preview = null;

                    let url = new URL(window.location.href);
                    url.pathname = '/app/imagine/';
                    window.history.pushState({}, '', url);

                    this.history.splice(this.history.indexOf(image), 1);
                });
        },

        select(image) {
            this.setRatio(image.output_file.width + ':' + image.output_file.height);
            this.preview = image;

            let url = new URL(window.location.href);
            url.pathname = '/app/imagine/' + image.id;
            window.history.pushState({}, '', url);
        },

        remove(image) {
            this.isDeleting = true;

            api.delete(`/library/images/${image.id}`)
                .then(() => {
                    this.preview = null;
                    window.modal.close();

                    toast.show("Image has been deleted successfully.", 'ti ti-trash');
                    this.isDeleting = false;

                    let url = new URL(window.location.href);
                    url.pathname = '/app/imagine/';
                    window.history.pushState({}, '', url);

                    this.history.splice(this.history.indexOf(image), 1);
                })
                .catch(error => this.isDeleting = false);
        },

        setRatio(ratio) {
            let [width, height] = ratio.split(':').map(Number);
            let r = height / width;
            this.ratio = r <= 0.6849 ? r : 0.6849;
        },

        copyImgToClipboard(image) {
            fetch(image.output_file.url)
                .then(res => res.blob())
                .then(blob => {
                    let item = new ClipboardItem({
                        [blob.type]: blob,
                    });

                    return navigator.clipboard.write([item])
                })
                .then(() => {
                    toast.success('Image copied to clipboard!');
                });
        }
    }));
}