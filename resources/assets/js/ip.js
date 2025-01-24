'use strict';

const IP_CACHE_KEY = 'ip_location_cache';
const CACHE_DURATION = 24 * 60 * 60 * 1000; // 24 hours in milliseconds

export class IpService {
    static async fetchIpLocation(ip) {
        try {
            const locationData = await this.getIpLocation(ip);
            if (locationData) {
                return {
                    latitude: locationData.latitude,
                    longitude: locationData.longitude
                };
            }
        } catch (error) {
            console.error('Error handling IP location:', error);
            return null;
        }
    }

    static async getIpLocation(ip) {
        // Check cache first
        const cache = this.getLocationFromCache(ip);
        if (cache) {
            return cache;
        }

        const response = await fetch(`https://freeipapi.com/api/json/${ip}`);
        if (!response.ok) {
            throw new Error(`API returned ${response.status}`);
        }

        const data = await response.json();
        if (!data.latitude || !data.longitude) {
            throw new Error('Invalid location data received');
        }

        // Cache the result
        this.cacheLocation(ip, data);
        return data;
    }

    static getLocationFromCache(ip) {
        const cache = JSON.parse(localStorage.getItem(IP_CACHE_KEY) || '{}');
        const now = Date.now();

        // Clean up all expired entries
        let hasExpired = false;
        for (const cachedIp in cache) {
            if ((now - cache[cachedIp].timestamp) >= CACHE_DURATION) {
                delete cache[cachedIp];
                hasExpired = true;
            }
        }

        if (hasExpired) {
            localStorage.setItem(IP_CACHE_KEY, JSON.stringify(cache));
        }

        const entry = cache[ip];
        return entry && (now - entry.timestamp) < CACHE_DURATION ? entry.data : null;
    }

    static cacheLocation(ip, data) {
        const cache = JSON.parse(localStorage.getItem(IP_CACHE_KEY) || '{}');

        cache[ip] = {
            data,
            timestamp: Date.now()
        };

        localStorage.setItem(IP_CACHE_KEY, JSON.stringify(cache));
    }
}
