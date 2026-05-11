import axios from 'axios';

const USER_STORAGE_KEY = 'ugda_user_profile';

let cachedUser = null;
let pendingUserRequest = null;

const readStoredUser = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    const rawUser = window.localStorage.getItem(USER_STORAGE_KEY);

    if (!rawUser) {
        return null;
    }

    try {
        return JSON.parse(rawUser);
    } catch (error) {
        window.localStorage.removeItem(USER_STORAGE_KEY);
        return null;
    }
};

const storeUser = (user) => {
    if (typeof window === 'undefined') {
        return;
    }

    if (!user) {
        window.localStorage.removeItem(USER_STORAGE_KEY);
        return;
    }

    window.localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(user));
};

const isExpiredSession = (error) => [401, 419].includes(error?.response?.status);

export const getCachedUser = () => {
    if (cachedUser) {
        return cachedUser;
    }

    cachedUser = readStoredUser();
    return cachedUser;
};

export const clearCachedUser = () => {
    cachedUser = null;
    pendingUserRequest = null;
    storeUser(null);
};

export const getCurrentUser = async ({ force = false } = {}) => {
    if (force) {
        cachedUser = null;
        pendingUserRequest = null;
    }

    if (cachedUser) {
        return cachedUser;
    }

    const storedUser = readStoredUser();
    if (storedUser && !force) {
        cachedUser = storedUser;
        return cachedUser;
    }

    if (pendingUserRequest) {
        return pendingUserRequest;
    }

    pendingUserRequest = axios.get('/api/user')
        .then(({ data }) => {
            cachedUser = data;
            storeUser(data);
            return data;
        })
        .catch((error) => {
            if (isExpiredSession(error)) {
                clearCachedUser();
            }

            throw error;
        })
        .finally(() => {
            pendingUserRequest = null;
        });

    return pendingUserRequest;
};
