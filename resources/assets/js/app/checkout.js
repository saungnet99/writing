'use strict';

import Alpine from 'alpinejs';
import api from './api';
import RestrictedInput from 'restricted-input';
import cardValidator from 'card-validator';
import { __ } from "../base/translate";

export function checkoutView() {
    Alpine.data('cardForm', () => ({
        model: {
            number: '',
            expiry: '',
            cvc: '',
        },

        ri: {
            number: null,
            expiry: null,
            cvc: null,
        },

        errors: {
            number: null,
            expiry: null,
            cvc: null,
        },

        error: null,
        card: {},
        data: {
            number: null,
            expire_month: null,
            expire_year: null,
            cvc: null,
        },

        init() {
            for (let input in this.ri) {
                this.ri[input] = new RestrictedInput({
                    element: this.$refs[input],
                    pattern: this.$refs[input].dataset.pattern
                });

                this.$watch('errors.' + input, (value) => {
                    this.error = this.errors.number || this.errors.expiry || this.errors.cvc;
                });
            }

            this.cardInput();
            this.expiryInput();
            this.cvcInput();
        },

        cardInput() {
            this.$watch('model.number', (value, current) => {
                this.data.number = null;
                this.errors.number = null;
                let validation = cardValidator.number(value);
                this.card = validation.card || {};

                if (validation.card) {
                    this.ri.number.setPattern(this.generateCardInputPattern(validation));
                    this.ri.cvc.setPattern('{{' + '9'.repeat(validation.card.code.size) + '}}');
                    this.$refs.cvc.setAttribute('placeholder', validation.card.code.name);
                    this.$refs.cvc.setAttribute('maxlength', validation.card.code.size);

                    if (this.model.cvc && this.model.cvc.length < validation.card.code.size) {
                        this.errors.cvc = __('Your card\'s security code is incomplete.');
                    }
                }

                if (value.length > current.length && validation.isValid && validation.card.type != "unionpay") {
                    if (!cardValidator.expirationDate(this.model.expiry).isValid) {
                        this.$refs.expiry.focus();
                    } else if (!cardValidator.cvv(this.model.cvc).isValid) {
                        this.$refs.cvc.focus();
                    }

                    this.data.number = value;
                } else if (!validation.isValid && !validation.isPotentiallyValid) {
                    this.errors.number = __('Invalid card number');
                }
            });

            this.$refs.number.addEventListener('blur', () => {
                let validation = cardValidator.number(this.model.number);
                if (!validation.isValid && this.model.number.length > 0) {
                    this.errors.number = validation.isPotentiallyValid ? __('Your card number is incomplete.') : __('Invalid card number');
                } else {
                    this.errors.number = null;
                }
            });
        },

        expiryInput() {
            this.$watch('model.expiry', (value, current) => {
                this.data.expire_month = null;
                this.data.expire_year = null;
                this.errors.expiry = null;
                let validation = cardValidator.expirationDate(value);

                if (validation.isValid) {
                    if (!cardValidator.cvv(this.model.cvc).isValid) {
                        this.$refs.cvc.focus();
                    }

                    this.data.expire_month = validation.month;
                    this.data.expire_year = validation.year;
                } else {
                    if (value.length == 1 && parseInt(value, 10) > 1) {
                        this.model.expiry = '0' + value + '/';
                    } else if (value.length == 3 && parseInt(value.substring(0, 2), 10) > '12') {
                        this.model.expiry = '0' + value[0] + '/' + value[1];
                    }
                }

                validation = cardValidator.expirationDate(this.model.expiry);
                if (!validation.isPotentiallyValid) {
                    this.errors.expiry = __('Your card\'s expiration date is invalid.');
                }
            });

            this.$refs.expiry.addEventListener('blur', () => {
                let validation = cardValidator.expirationDate(this.model.expiry);
                if (!validation.isValid && this.model.expiry.length > 0) {
                    this.errors.expiry = validation.isPotentiallyValid ? __('Your card\'s expiration date is incomplete.') : __('Your card\'s expiration date is invalid.');
                } else {
                    this.errors.expiry = null;
                }
            });

            this.$refs.expiry.addEventListener('keyup', (e) => {
                if (e.key == 'Backspace' && this.model.expiry.length == 0) {
                    this.$refs.number.focus();
                }
            });
        },

        cvcInput() {
            this.$watch('model.cvc', (value, current) => {
                this.data.cvc = null;
                this.errors.cvc = null;
                let validation = cardValidator.cvv(value, this.$refs.cvc.getAttribute('maxlength') || 3);

                if (validation.isValid) {
                    this.data.cvc = value;
                } else if (!validation.isPotentiallyValid) {
                    this.errors.cvc = __('Your card\'s security code is invalid.');
                }
            });

            this.$refs.cvc.addEventListener('blur', () => {
                let validation = cardValidator.cvv(this.model.cvc, this.$refs.cvc.getAttribute('maxlength') || 3);
                if (!validation.isValid && this.model.cvc.length > 0) {
                    this.errors.cvc = validation.isPotentiallyValid ? __('Your card\'s security code is incomplete.') : __('Your card\'s security code is invalid.');
                } else {
                    this.errors.cvc = null;
                }
            });

            this.$refs.cvc.addEventListener('keyup', (e) => {
                if (e.key == 'Backspace' && this.model.cvc.length == 0) {
                    this.$refs.expiry.focus();
                }
            });
        },

        generateCardInputPattern(verification) {
            let cardInfo = verification.card;
            let pattern = '';
            let lastIndex = 0;

            // Add parts for each gap
            cardInfo.gaps.forEach((gap, index) => {
                const gapSize = gap - lastIndex;
                pattern += '{{' + '9'.repeat(gapSize) + '}} ';
                lastIndex = gap;
            });

            // Add the last part, considering the maximum length
            const maxLength = Math.max(...cardInfo.lengths);
            const remainingLength = maxLength - lastIndex;
            pattern += '{{' + '9'.repeat(remainingLength) + '}}';

            return pattern.trim();
        },

        icon() {
            return `<svg><use xlink:href="#icon-${this.card.type || 'default'}"></use></svg>`;
        }
    }));

    Alpine.data("stripe", (key) => ({
        stripe: null,
        error: null,
        disabled: true,
        card: null,

        init() {
            this.stripe = Stripe(key);
            let elements = this.stripe.elements();
            this.card = elements.create("card", {
                style: {
                    base: {
                        fontSize: '16px',
                        color: `rgb(${getComputedStyle(document.documentElement).getPropertyValue('--color-content')})`,
                    }
                }
            });

            this.card.mount(this.$refs.card);
            this.card.on('change', ({ complete, error }) => {
                this.disabled = !complete;
                this.error = error ? error.message : null;
            });
        },

        async submit() {
            if (this.disabled || this.processing) {
                return;
            }

            this.processing = 'stripe';
            let response;

            try {
                response = await api.post('/billing/checkout', {
                    id: this.plan.id,
                    gateway: 'stripe',
                    coupon: this.coupon.code,
                });
            } catch (error) {
                let data = await error.json();

                this.error = data.message || null;
                this.disabled = false;
                this.processing = null;
                return;
            }

            let data = await response.json();
            let clientSecret = data.purchase_token;

            const confirmIntent = clientSecret.startsWith('seti_')
                ? this.stripe.confirmCardSetup
                : this.stripe.confirmCardPayment;

            let result = await confirmIntent(clientSecret, {
                payment_method: {
                    card: this.card
                }
            });

            if (result.error) {
                this.error = result.error.message;
                this.disabled = false;

                this.processing = null;
            } else {
                let url = 'payment-callback/' + data.id + '/stripe';

                if (result.setupIntent) {
                    url += '?setup_intent_id=' + result.setupIntent.id;
                } else {
                    url += '?payment_intent_id=' + result.paymentIntent.id;
                }

                window.location.href = url;
                return;
            }
        }
    }))

    Alpine.data('checkout', (plan = {}) => ({
        plan: plan,
        coupon: {
            code: null,
            error: null,
        },
        processing: null,
        error: null,
        showAddressForm: false,
        canProceed: false,
        applying: false,

        init() {
            this.canProceed = plan.member_cap === null || this.$store.workspace.users.length + this.$store.workspace.invitations.length <= plan.member_cap
        },

        applyCoupon() {
            if (this.applying || !this.coupon.code) {
                return;
            }

            this.applying = true;

            api.config.toast = false;
            api.get('/billing/plans/' + this.plan.id, { coupon: this.coupon.code })
                .then(response => {
                    this.plan = { ...this.plan, ...response.data }
                })
                .catch(error => {
                    this.coupon.error = error.message;
                })
                .finally(() => {
                    api.config.toast = true;
                    this.applying = false;
                });
        },

        removeCoupon() {
            if (this.applying || !this.coupon.code) {
                return;
            }

            this.applying = true;

            api.get('/billing/plans/' + this.plan.id)
                .then(response => {
                    this.coupon.code = null;
                    this.coupon.error = null;
                    this.plan = { ...this.plan, ...response.data }
                })
                // .catch(error => this.applying = false)
                .finally(() => {
                    this.applying = false;
                });;
        },

        saveAddress(id, form) {
            if (this.processing) {
                return;
            }

            this.processing = true;

            api.post(`/workspaces/${id}`, new FormData(form))
                .then(response => {
                    this.$store.workspace = response.data;
                    this.processing = false;
                    this.showAddressForm = false;
                }).catch(error => this.processing = false);
        },

        purchase(gateway = '', card = null) {
            if (this.processing) {
                return;
            }

            this.processing = gateway;

            api
                .post('/billing/checkout', {
                    id: this.plan.id,
                    gateway: gateway,
                    card: card,
                    coupon: this.coupon.code,
                })
                .then(response => response.json())
                .then(data => {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }

                    if (data.id) {
                        window.location.href = 'app/billing/orders/' + data.id + '/receipt';
                        return;
                    }

                    this.processing = null;
                })
                .catch(error => {
                    let msg = 'An unexpected error occurred! Please try again later!';

                    this.processing = null;
                    this.error = error.message || msg;
                });
        }
    }));
}