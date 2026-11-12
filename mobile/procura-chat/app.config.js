export default {
    expo: {
        name: 'PROCURA Chat',
        slug: 'procura-chat',
        version: '1.0.0',
        scheme: 'procurachat',
        orientation: 'portrait',
        userInterfaceStyle: 'light',
        ios: { supportsTablet: true, bundleIdentifier: 'com.asiprocura.chat' },
        android: { package: 'com.asiprocura.chat', permissions: ['POST_NOTIFICATIONS'] },
        plugins: ['expo-secure-store', 'expo-sharing', ['expo-notifications', { defaultChannel: 'procura-approvals', color: '#171F70' }]],
        extra: {
            apiUrl: process.env.EXPO_PUBLIC_PROCURA_API_URL || 'https://asiprocura.allianzsynergia.com.ph/api/mobile/v1',
            eas: { projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID || '' },
        },
    },
};
