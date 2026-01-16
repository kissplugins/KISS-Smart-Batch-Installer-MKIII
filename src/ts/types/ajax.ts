// WordPress AJAX response envelopes
export type WpAjaxSuccess<T = any> = { success: true; data: T };
export type WpAjaxError<T = any> = { success: false; data: T };
export type WpAjaxResponse<T = any> = WpAjaxSuccess<T> | WpAjaxError;

// Common debug/progress structures surfaced by server
export interface ProgressUpdate {
  step: string;
  status: 'info' | 'success' | 'warning' | 'error';
  message?: string;
  timestamp?: number;
}

export interface InstallPluginRequest {
  action: 'sbi_install_plugin';
  repository: string; // repo slug only
  owner: string;
  activate: boolean;
  /** v1.0.71: Client-generated correlation id for end-to-end tracing */
  correlation_id?: string;
  nonce: string;
}

export interface InstallPluginSuccessData {
  message: string;
  repository: string;
  total_time?: number;
  debug_steps?: unknown[];
  progress_updates?: ProgressUpdate[];
  // additional fields returned by server are allowed
  [k: string]: unknown;
}

export interface ActivatePluginRequest {
  action: 'sbi_activate_plugin';
  repository: string; // repo slug only
  plugin_file: string;
  /** v1.0.71: Client-generated correlation id for end-to-end tracing */
  correlation_id?: string;
  nonce: string;
}

export interface DeactivatePluginRequest {
  action: 'sbi_deactivate_plugin';
  repository: string; // repo slug only
  plugin_file: string;
  /** v1.0.71: Client-generated correlation id for end-to-end tracing */
  correlation_id?: string;
  nonce: string;
}

export interface RefreshStatusRequest {
  action: 'sbi_refresh_status';
  repositories: string[]; // full_name values
  /** v1.0.71: Client-generated correlation id for end-to-end tracing */
  correlation_id?: string;
  nonce: string;
}

export interface RefreshStatusResultItem {
  repository: string; // full_name
  state: string; // server enum string
  // v1.0.70: Batch refresh is now a wrapper over canonical refresh payload
  // (same shape as sbi_refresh_repository), so row HTML can be used by callers.
  row_html?: string;
  error?: string;
}

export interface RefreshStatusSuccessData {
  results: RefreshStatusResultItem[];
}

