'use strict';

import Alpine from 'alpinejs';
import api from './api';
import { listResources } from './helpers';
import { toast } from '../base/toast';
import { CustomFormData } from '../formdata';

export function settingsView() {
    Alpine.data('settings', (path) => ({
        required: [],
        isProcessing: false,
        plans: [],
        plansFetched: false,

        init() {
            listResources('/plans')
                .then(plans => {
                    this.plans = plans;
                    this.plansFetched = true;
                });
        },

        submit() {
            if (this.isProcessing) {
                return;
            }

            this.isProcessing = true;

            let data = new CustomFormData(this.$refs.form);

            api.post(`/options${this.$refs.form.dataset.path || ''}`, data)
                .then(response => {
                    this.isProcessing = false;

                    toast.show(
                        'Changes saved successfully!',
                        'ti ti-square-rounded-check-filled'
                    );
                })
                .catch(error => this.isProcessing = false);
        },

        clearCache() {
            if (this.isProcessing) {
                return;
            }

            this.isProcessing = true;

            api.delete(`/cache`)
                .then(() => {
                    this.isProcessing = false;

                    toast.show(
                        'Cache cleared successfully!',
                        'ti ti-square-rounded-check-filled'
                    );
                })
                .catch(error => this.isProcessing = false);
        }
    }));

    Alpine.data('llm', (models = [], headers = []) => ({
        models: models,
        headers: headers,
        key: null,
        isProcessing: false,
        llmKey: '',
        currentResource: null,
        isDeleting: false,

        submit() {
            if (this.isProcessing) {
                return;
            }

            this.isProcessing = true;

            let data = new CustomFormData(this.$refs.form);

            api.post(`/options`, data)
                .then(response => {
                    if (models.length > 0) {
                        this.isProcessing = false;
                        toast.success(
                            'Changes saved successfully!',
                            'ti ti-square-rounded-check-filled'
                        );
                    } else {
                        toast.defer(
                            'New LLM server added successfully!',
                            'ti ti-square-rounded-check-filled'
                        );

                        window.location = '/admin/settings';
                    }
                })
                .catch(error => this.isProcessing = false);
        },

        deleteLlmServer(id) {
            this.isDeleting = true;

            api.delete(`/options/llms/${id}`)
                .then(() => {
                    this.isDeleting = false;
                    window.modal.close();
                    toast.success('Deleted successfully!');

                    this.$refs[`llm-${id}`].remove();
                });
        },

        setLlmKey(value) {
            if (!value) {
                this.llmKey = '';
                return;
            }

            this.llmKey = value.toLowerCase().replace(/[^a-z0-9]/g, '');
        },

        maskAuthKey(key, first = 3, last = 0, prefix = 'Bearer ') {
            key = key.trim();

            return key.length > first + last
                ? `${prefix}${key.slice(0, first)}${'*'.repeat(key.length - first - last)}${last > 0 ? key.slice(-last) : ''}`
                : `${prefix}${key}`;
        },

        setModelName(value, nameRef) {
            let modelString = value.includes('/') ? value.split('/').slice(1).join('/') : value;
            modelString = modelString.split(':')[0];
            modelString = modelString.replace(/-/g, ' ');
            modelString = modelString.replace(/_/g, '.');
            modelString = modelString.replace(/(\d+(?:\.\d+)?)/g, ' $1 ');
            modelString = modelString
                .split('/')
                .map(part =>
                    part
                        .trim()
                        .split(' ')
                        .filter(word => word)
                        .map(word =>
                            word.charAt(0).toUpperCase() +
                            (word.slice(1).match(/[A-Z]/) ? word.slice(1) : word.slice(1).toLowerCase())
                        )
                        .join(' ')
                )
                .join('/');

            nameRef.value = modelString;
        },

        init() {
            this.models.forEach(model => {
                model.tools = model.tools === '1' || model.tools === true;
                model.vision = model.vision === '1' || model.vision === true;
            });

            if (this.models.length === 0) {
                this.models = [
                    {
                        provider: '',
                        key: '',
                        name: '',
                        tools: false,
                        vision: false
                    }
                ];
            }
        },

        addModel() {
            this.models.push({ key: '', name: '' });
        },

        removeModel(index) {
            this.models.splice(index, 1);
        },

        addHeader() {
            this.headers.push({ key: '', value: '' });
        },

        removeHeader(index) {
            this.headers.splice(index, 1);
        }
    }))

    Alpine.data('colorSchemes', (light, dark, def) => ({
        light: light,
        dark: dark,
        def: def,

        init() {
            ['light', 'dark'].forEach((scheme) => {
                this.$watch(scheme, (val) => {
                    if (!val) {
                        scheme == 'light' ? this.dark = true : this.light = true;

                        if (this.def == scheme) {
                            this.def = 'system';
                        }
                    }
                });
            });
        },
    }))
}