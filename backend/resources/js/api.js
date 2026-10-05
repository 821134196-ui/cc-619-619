import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        const msg = error.response?.data?.message || error.message || '请求失败';
        return Promise.reject(new Error(msg));
    }
);

export default api;

export const yuan = (cents) => `¥${((cents || 0) / 100).toFixed(2)}`;

export const SOURCE_LABEL = {
    broken_stock: '窗口实物破损',
    lost_issued: '发放后丢失',
    stocktake_shortage: '盘点盘亏',
};
