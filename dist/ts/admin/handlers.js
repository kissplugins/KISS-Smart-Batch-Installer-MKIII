import { wpAjaxFetch } from '../lib/ajaxClient';
function requireAjax(windowObj) {
    const w = windowObj;
    if (!w.sbiAjax)
        throw new Error('sbiAjax not found on window');
    return w.sbiAjax;
}
// Utility to satisfy Record<string, unknown> requirement when calling fetch
function asPayload(obj) {
    return obj;
}
export async function installPlugin(windowObj, owner, repository, activate = false, correlationId) {
    const sbiAjax = requireAjax(windowObj);
    const payload = {
        action: 'sbi_install_plugin',
        owner,
        repository,
        activate,
        correlation_id: correlationId,
        nonce: sbiAjax.nonce,
    };
    return wpAjaxFetch(windowObj, asPayload(payload));
}
export async function activatePlugin(windowObj, repository, plugin_file, correlationId) {
    const sbiAjax = requireAjax(windowObj);
    const payload = {
        action: 'sbi_activate_plugin',
        repository,
        plugin_file,
        correlation_id: correlationId,
        nonce: sbiAjax.nonce,
    };
    return wpAjaxFetch(windowObj, asPayload(payload));
}
export async function deactivatePlugin(windowObj, repository, plugin_file, correlationId) {
    const sbiAjax = requireAjax(windowObj);
    const payload = {
        action: 'sbi_deactivate_plugin',
        repository,
        plugin_file,
        correlation_id: correlationId,
        nonce: sbiAjax.nonce,
    };
    return wpAjaxFetch(windowObj, asPayload(payload));
}
export async function refreshStatus(windowObj, repositories, correlationId) {
    const sbiAjax = requireAjax(windowObj);
    const payload = {
        action: 'sbi_refresh_status',
        repositories,
        correlation_id: correlationId,
        nonce: sbiAjax.nonce,
    };
    return wpAjaxFetch(windowObj, asPayload(payload));
}
