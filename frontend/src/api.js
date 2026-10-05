// 极简 API 封装；员工接口自动带上当前身份头
let staffId = null;

export function setStaffId(id) {
  staffId = id;
}
export function getStaffId() {
  return staffId;
}

async function request(method, path, body) {
  const res = await fetch(`/api${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(staffId ? { 'X-Staff-User-Id': String(staffId) } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  let payload = null;
  const text = await res.text();
  if (text) {
    try { payload = JSON.parse(text); } catch { payload = { raw: text }; }
  }

  if (!res.ok) {
    const message = payload?.message || `请求失败（${res.status}）`;
    const err = new Error(message);
    err.status = res.status;
    err.payload = payload;
    throw err;
  }
  return payload;
}

export const api = {
  get: (p) => request('GET', p),
  post: (p, b) => request('POST', p, b ?? {}),
};

export const LANGUAGE_LABELS = {
  zh: '中文',
  en: 'English',
  ja: '日本語',
};

export function lang(code) {
  return LANGUAGE_LABELS[code] || code;
}

export const STATUS_LABELS = {
  booked: '已预约',
  checked_in: '已发放设备',
  completed: '已完成归还',
  released: '已改签释放',
  cancelled: '已取消',
  no_show: '未到场',
};

export const DEVICE_STATUS_LABELS = {
  available: '可发放',
  assigned: '占用中',
  faulty: '故障锁定',
};

export const SWITCH_STATUS_LABELS = {
  pending: '待批准',
  approved: '已批准切换',
  rejected: '已驳回',
};

export function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日 ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function fmtTime(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}
