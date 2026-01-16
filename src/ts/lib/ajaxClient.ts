import type { WpAjaxResponse } from '../types/ajax';
import type { SbiAjax } from '../types/wp-globals';
import { mapExceptionToError, mapResponseToError } from './errors';

export interface AjaxOptions {
  method?: 'GET' | 'POST';
  timeoutMs?: number; // default 30000
  headers?: Record<string, string>;
  /**
   * Optional caller-provided correlation id. If omitted, wpAjaxFetch will generate one
   * and inject it into the payload as `correlation_id`.
   */
  correlationId?: string;
}

function timeout(signal: AbortSignal | undefined, ms: number): AbortController | undefined {
  if (typeof AbortController === 'undefined') return undefined;
  const ctrl = new AbortController();
  const id = setTimeout(() => ctrl.abort(), ms);
  // When caller passes an external signal we could chain, but keep minimal
  void signal; // not used for now
  // Clear will be responsibility of caller lifecycle; for small calls, process ends anyway
  return ctrl;
}

function formEncode(body: Record<string, unknown>): string {
  return Object.entries(body)
    .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(String(v)))
    .join('&');
}

export async function wpAjaxFetch<TData = any>(
  windowObj: Window,
  payload: Record<string, unknown>,
  opts: AjaxOptions = {}
): Promise<WpAjaxResponse<TData>> {
  const w = windowObj as any as { sbiAjax?: SbiAjax; sbiDebug?: any };
  if (!w.sbiAjax) throw new Error('sbiAjax not found on window');

  const url = w.sbiAjax.ajaxurl;
  const method = opts.method || 'POST';
  const timeoutMs = opts.timeoutMs ?? 30000;
  const headers: Record<string, string> = {
    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
    ...(opts.headers || {}),
  };

  const controller = timeout(undefined, timeoutMs);

  // v1.0.71: Correlation ID propagation for end-to-end diagnostics
  const payloadCid = typeof (payload as any)?.correlation_id === 'string' ? String((payload as any).correlation_id) : '';
  const correlationId =
    payloadCid ||
    opts.correlationId ||
    (windowObj.crypto?.randomUUID ? windowObj.crypto.randomUUID() : '') ||
    Date.now().toString();
  const finalPayload: Record<string, unknown> = { ...payload, correlation_id: correlationId };

  try {
    const resp = await fetch(url, {
      method,
      headers,
      body: method === 'POST' ? formEncode(finalPayload) : undefined,
      signal: controller?.signal,
      credentials: 'same-origin',
    });

    if (!resp.ok) {
      const err = mapResponseToError(resp, correlationId, url, method);
      w.sbiDebug?.addEntry('error', 'AJAX Error', `cid=${correlationId} ` + JSON.stringify(err));
      return { success: false, data: err as any };
    }

    const data = (await resp.json()) as WpAjaxResponse<TData>;
    return data;
  } catch (e) {
    const err = mapExceptionToError(e, correlationId, url, method);
    w.sbiDebug?.addEntry('error', 'AJAX Exception', `cid=${correlationId} ` + JSON.stringify(err));
    return { success: false, data: err as any };
  }
}

