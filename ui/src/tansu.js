/**
 * Entry point: defines <tansu-explorer>, <tansu-picker> and <tansu-documents>.
 *
 *   <script type="module" src="/vendor/tansu/tansu.js"></script>
 */
import { TansuExplorer } from './explorer.js';
import { TansuPicker } from './picker.js';
import { TansuDocuments } from './documents.js';

export { TansuClient, TansuError } from './api.js';
export { setMessages } from './i18n.js';
export { TansuExplorer, TansuPicker, TansuDocuments };

if (!customElements.get('tansu-explorer')) customElements.define('tansu-explorer', TansuExplorer);
if (!customElements.get('tansu-picker')) customElements.define('tansu-picker', TansuPicker);
if (!customElements.get('tansu-documents')) customElements.define('tansu-documents', TansuDocuments);
