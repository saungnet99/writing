`use strict`;

import Alpine from 'alpinejs';
import api from './api';

export function searchForm() {
    Alpine.data('search', () => ({
        isProcessing: false,
        showResults: false,
        results: [],

        init() {
            this.bindKeyboardShortcuts();
        },

        bindKeyboardShortcuts() {
            window.addEventListener('keydown', (e) => {
                if (e.metaKey && e.key === 'k') {
                    e.preventDefault();
                    this.$refs.input.focus();
                } else if (e.key === 'Escape') {
                    this.$refs.input.blur();
                    this.showResults = false;
                }
            });
        },

        search(query) {
            this.isProcessing = true;

            api
                .get('/search', { query: query })
                .then(response => response.json())
                .then(list => {
                    this.results = list.data;
                    this.isProcessing = false;
                    this.showResults = this.results.length > 0;
                })
                .catch(error => {
                    this.isProcessing = false;
                    this.showResults = false;
                });
        }
    }));
}