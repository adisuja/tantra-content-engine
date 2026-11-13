import { readonly, shallowRef } from 'vue';

export type ConnectChannelStep =
    | 'grid'
    | 'details'
    | 'instagram'
    | 'instagram-facebook'
    | 'telegram'
    | 'setup';

export interface ConnectChannelState {
    id: number;
    flow: 'connect' | 'goal';
    step: ConnectChannelStep;
    reconnectId?: string;
    goalAccountId?: string;
    detailsPlatform?: string;
    // tantra: the network whose "Needs setup" step is open.
    setupPlatform?: string;
    canGoBack: boolean;
}

let nextId = 0;

const state = shallowRef<ConnectChannelState | null>(null);

const open = (): void => {
    state.value = {
        id: ++nextId,
        flow: 'connect',
        step: 'grid',
        canGoBack: false,
    };
};

const openAt = (
    step: Exclude<ConnectChannelStep, 'grid' | 'details'>,
    reconnectId?: string,
): void => {
    state.value = {
        id: ++nextId,
        flow: 'connect',
        step,
        reconnectId,
        canGoBack: false,
    };
};

const openGoal = (accountId: string): void => {
    state.value = {
        id: ++nextId,
        flow: 'goal',
        step: 'grid',
        goalAccountId: accountId,
        canGoBack: false,
    };
};

const goTo = (step: Exclude<ConnectChannelStep, 'grid' | 'details'>): void => {
    if (!state.value) return;
    state.value = {
        ...state.value,
        step,
        canGoBack: true,
    };
};

const showDetails = (platform: string): void => {
    if (!state.value) return;
    state.value = {
        ...state.value,
        step: 'details',
        detailsPlatform: platform,
        canGoBack: true,
    };
};

// tantra: a network without developer app keys opens its setup step.
const showSetup = (platform: string, fromGrid = true): void => {
    state.value =
        state.value && fromGrid
            ? {
                  ...state.value,
                  step: 'setup',
                  setupPlatform: platform,
                  canGoBack: true,
              }
            : {
                  id: ++nextId,
                  flow: 'connect',
                  step: 'setup',
                  setupPlatform: platform,
                  canGoBack: false,
              };
};

const back = (): void => {
    if (!state.value) return;

    state.value = {
        ...state.value,
        step: 'grid',
        detailsPlatform: undefined,
        setupPlatform: undefined,
        canGoBack: false,
    };
};

const close = (): void => {
    state.value = null;
};

export const useConnectChannelDialog = () => ({
    state: readonly(state),
    open,
    openAt,
    openGoal,
    goTo,
    showDetails,
    showSetup,
    back,
    close,
});
