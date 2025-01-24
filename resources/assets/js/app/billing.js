'use strict';

import Alpine from 'alpinejs';
import api from './api';
import { getPlanList } from './helpers';
import { toast } from '../base/toast';

export function billingView() {
    Alpine.data('billing', () => ({
        orders: [],

        init() {
            this.getOrders();
        },

        cancelSubscription() {
            api.delete(`/billing/subscription`)
                .then(() => {
                    toast.success('Subscription cancelled!');

                    window.modal.close();

                    // Reload the page to reflect the changes
                    window.location.reload();
                });
        },

        getOrders() {
            api.get(`/billing/orders`, {
                sort: 'created_at:desc',
                limit: 3
            })
                .then(response => response.json())
                .then(list => this.orders = list.data);
        }
    }));

    Alpine.data('plans', () => ({
        state: 'initial',
        plans: {
            monthly: [],
            yearly: [],
            lifetime: []
        },
        freePlan: null,
        cycle: null,
        showSwitch: false,

        init() {
            getPlanList().then(plans => {
                if (!plans) {
                    this.state = 'empty';
                    return;
                }

                let free = plans.filter(
                    plan =>
                        plan.price === 0 && ['monthly', 'yearly'].includes(plan.billing_cycle)
                );

                if (free.length == 1) {
                    this.freePlan = free[0];
                }

                ['monthly', 'yearly', 'lifetime'].forEach(cycle => {
                    this.plans[cycle] = plans.filter(
                        plan =>
                            plan.billing_cycle === cycle && (!this.freePlan || plan.id !== this.freePlan.id)
                    );
                });

                // Set cycle to the first available cycle
                if (this.plans.monthly.length > 0) {
                    this.cycle = 'monthly';
                } else if (this.plans.yearly.length > 0) {
                    this.cycle = 'yearly';
                }

                if (!this.$store.workspace.is_eligible_for_free_plan) {
                    this.freePlan = null;
                }

                this.state = this.freePlan || this.plans.monthly.length > 0 || this.plans.yearly.length > 0 ? 'normal' : 'empty';

                // Show the switcher if at least two cycles are available

                // Set showSwitch to true if at least two plans have a non-zero length
                this.showSwitch = Object.values(this.plans).map(plan => plan.length).filter(length => length > 0).length >= 2;
            });
        }
    }));

    Alpine.data('packs', () => ({
        state: 'initial',
        packs: [],

        init() {
            getPlanList().then(plans => {
                if (!plans) {
                    this.state = 'empty';
                    return;
                }

                this.packs = plans.filter(plan => plan.billing_cycle === 'one-time');
                this.state = this.packs.length > 0 ? 'normal' : 'empty';
            });
        }
    }));
}