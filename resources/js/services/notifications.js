import axios from 'axios';

const notificationState = {
    unreadCount: 0,
    latest: [],
};

const setNotificationState = (payload) => {
    notificationState.unreadCount = payload.unread_count ?? 0;
    notificationState.latest = payload.latest ?? [];

    window.dispatchEvent(new CustomEvent('ugda:notifications-updated', {
        detail: { ...notificationState },
    }));
};

export const getNotificationState = () => notificationState;

export const fetchNotificationSummary = async () => {
    const { data } = await axios.get('/api/notifications/summary');
    setNotificationState(data);

    return data;
};

export const markNotificationAsRead = async (id) => {
    const { data } = await axios.post(`/api/notifications/${id}/read`);
    await fetchNotificationSummary();

    return data.notification;
};

export const markAllNotificationsAsRead = async () => {
    const { data } = await axios.post('/api/notifications/mark-all-read');
    await fetchNotificationSummary();

    return data;
};
