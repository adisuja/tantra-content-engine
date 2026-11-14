import { readonly, shallowRef } from 'vue';

export type ConnectChannelStep =
    | 'grid'
    | 'details'
    | 'instagram'
    | 'instagram-facebook'
    | 'telegram'
    | 'network';

export interface ConnectChannelState {
    id: number;
    flow: 'connect' | 'goal';
    step: ConnectChannelStep;
    reconnectId?: string;
    goalAccountId?: string;
    detailsPlatform?: string;
    // tantra: the network whose connect panel is open (logo grid, Adi 2026-11-14).
    network?: string;
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

// tantra: a logo in the grid opens that network's connect panel; from
// anywhere else (sidebar, inline grid) the dialog opens straight at it.
const showNetwork = (platform: string, fromGrid = true): void => {
    state.value =
        state.value && fromGrid
            ? {
                  ...state.value,
                  step: 'network',
                  network: platform,
                  canGoBack: true,
              }
            : {
                  id: ++nextId,
                  flow: 'connect',
                  step: 'network',
                  network: platform,
                  canGoBack: true,
              };
};

const back = (): void => {
    if (!state.value) return;

    state.value = {
        ...state.value,
        step: 'grid',
        detailsPlatform: undefined,
        network: undefined,
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
    showNetwork,
    back,
    close,
});
