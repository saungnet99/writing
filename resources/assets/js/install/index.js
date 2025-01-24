'use strict';

import Alpine from 'alpinejs';
import mask from '@alpinejs/mask'
import { installerView } from './installer.js';

installerView();

// Call after views are loaded
Alpine.plugin(mask);
Alpine.start();