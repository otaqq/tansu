/**
 * Entry point: defines <tansu-explorer> and <tansu-picker>.
 *
 *   <script type="module" src="/vendor/tansu/tansu.js"></script>
 */
import { TansuExplorer } from './explorer.js';
import { TansuPicker } from './picker.js';

export { TansuClient, TansuError } from './api.js';
export { setMessages } from './i18n.js';
export { TansuExplorer, TansuPicker };

if (!customElements.get('tansu-explorer')) customElements.define('tansu-explorer', TansuExplorer);
if (!customElements.get('tansu-picker')) customElements.define('tansu-picker', TansuPicker);
