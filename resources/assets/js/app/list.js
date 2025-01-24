'use strict';

import Alpine from 'alpinejs';
import api from './api';
import { getCategoryList, getPlanList } from './helpers';
import { toast } from '../base/toast';
import { getBillingCycleLabel } from '../helpers';
export function listView() {
    Alpine.data('lc', (filters = [], sort = []) => ({
        filters: filters,
        sort: sort,

        orderby: null,
        dir: null,

        params: {
            query: null,
            sort: null
        },

        // total: null,

        init() {
            this.filters.forEach(filter => this.params[filter.model] = null);
            this.getCategories();
            this.getPlans();

            let sortparams = ['orderby', 'dir'];
            sortparams.forEach(param => {
                this.$watch(param, () => {
                    this.params.sort = null;
                    if (this.orderby) {
                        this.params.sort = this.orderby;

                        if (this.dir) {
                            this.params.sort += `:${this.dir}`;
                        }
                    }
                })
            });

            this.$watch('params', (params) => {
                this.$dispatch('lc.filtered', params)
                this.updateUrl();
            });

            this.parseUrl();

            window.addEventListener('lc.reset', () => this.resetFilters());
        },

        resetFilters() {
            for (const key in this.params) {
                if (key != 'sort') {
                    this.params[key] = null;
                }
            }
        },

        getCategories() {
            let filter = this.filters.find(filter => filter.model == 'category');

            if (!filter) {
                return;
            }

            getCategoryList()
                .then(categories => {
                    categories.forEach(category => {
                        filter.options.push({
                            value: category.id,
                            label: category.title
                        });

                        this.parseUrl();
                    });
                });
        },

        getPlans() {
            let filter = this.filters.find(filter => filter.model == 'plan');
            if (!filter) {
                return;
            }

            let cycles = filter.billing_cycle || [];

            getPlanList()
                .then(plans => {
                    plans.forEach(plan => {
                        if (cycles.length == 0 || cycles.includes(plan.billing_cycle)) {
                            filter.options.push({
                                value: plan.id,
                                label: plan.title + ' / ' + getBillingCycleLabel(plan.billing_cycle)
                            });
                        }
                    });

                    this.parseUrl();
                });
        },

        parseUrl() {
            let url = new URL(window.location.href);
            let params = new URLSearchParams(url.search.slice(1));

            for (const key in this.params) {
                if (!params.has(key)) {
                    continue;
                }

                let filter = this.filters.find(f => f.model == key);

                if (!filter) {
                    continue;
                }

                let option = filter.options?.find(o => o.value == params.get(key));

                if (!option && !filter.hidden) {
                    continue;
                }

                this.params[key] = params.get(key);
            }

            if (this.params.sort) {
                let sort = this.params.sort.split(':');
                this.orderby = sort[0];
                this.dir = sort[1] || 'asc';
            }
        },

        updateUrl() {
            let url = new URL(window.location.href);
            let params = new URLSearchParams(url.search.slice(1));

            for (const key in this.params) {
                if (this.params[key]) {
                    params.set(key, this.params[key]);
                } else {
                    params.delete(key);
                }
            }

            window.history.pushState({}, '', `${url.origin}${url.pathname}?${params}`);
        }
    }));

    Alpine.data('list', (basePath, strings = [], limit = 25, count = true) => ({
        state: 'initial',

        params: {},
        total: null,
        all: null,
        cursor: null,
        resources: [],
        isLoading: false,
        hasMore: true,
        isFiltered: false,
        currentResource: null,
        isDeleting: false,

        init() {
            this.loadMore();

            if (count) {
                this.getTotalCount();
            }

            let timer = null;
            timer = setTimeout(() => this.retrieveResources(), 200);

            window.addEventListener('lc.filtered', (e) => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    this.params = e.detail;
                    this.retrieveResources(true);
                }, 200);
            });
        },

        applyQueryParams(params) {
            // Append the current query to the params (get from the URL)
            let url = new URL(window.location.href);
            let searchParams = new URLSearchParams(url.search.slice(1));

            // Append all the query params to the params object
            searchParams.forEach((value, key) => {
                params[key] = value;
            });
        },

        getTotalCount() {
            let params = {};
            this.applyQueryParams(params);

            api.get(`${basePath}/count`, params)
                .then(response => response.json())
                .then(response => this.total = response.count);

            // Count all resources
            if (!params.hasOwnProperty('all')) {
                params.all = true;
                api.get(`${basePath}/count`, params)
                    .then(response => response.json())
                    .then(response => this.all = response.count);
            }
        },

        retrieveResources(reset = false) {
            this.isLoading = true;
            let params = {
                limit: limit
            };
            let isFiltered = false;

            if (!reset) {
                this.applyQueryParams(params);
            }

            for (const key in this.params) {
                if (this.params[key]) {
                    if (key != 'sort') {
                        isFiltered = true;
                    }

                    params[key] = this.params[key];
                }
            }

            this.isFiltered = isFiltered;

            if (!reset && this.cursor) {
                params.starting_after = this.cursor;
            }

            api.get(basePath, params)
                .then(response => response.json())
                .then(list => {
                    this.state = 'loaded';

                    this.resources = reset
                        ? list.data
                        : this.resources.concat(list.data);

                    if (this.resources.length > 0) {
                        this.cursor = this.resources[this.resources.length - 1].id;
                    } else {
                        this.state = 'empty';
                    }

                    this.isLoading = false;
                    this.hasMore = list.data.length >= limit;
                });
        },

        loadMore() {
            window.addEventListener('scroll', () => {
                if (
                    this.hasMore
                    && !this.isLoading
                    && (window.innerHeight + window.scrollY + 500) >= document.documentElement.scrollHeight
                ) {
                    this.retrieveResources();
                }
            });
        },

        toggleStatus(resource) {
            resource.status = resource.status == 1 ? 0 : 1;

            api.post(`${basePath}/${resource.id}`, {
                status: resource.status
            });
        },

        deleteResource(resource) {
            this.isDeleting = true;

            api.delete(`${basePath}/${resource.id}`)
                .then(() => {
                    this.resources.splice(this.resources.indexOf(resource), 1);
                    window.modal.close();

                    this.currentResource = null;
                    toast.show(strings.delete_success, 'ti ti-trash')

                    this.isDeleting = false;
                })
                .catch(error => { this.isDeleting = false; });
        },

        getBillingCycleLabel(cycle) {
            return getBillingCycleLabel(cycle);
        }
    }))
}