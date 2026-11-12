import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, BackHandler, FlatList, Image, KeyboardAvoidingView, Linking, Modal, Platform, Pressable, SafeAreaView, ScrollView, Text, View } from 'react-native';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import * as DocumentPicker from 'expo-document-picker';
import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';
import * as Crypto from 'expo-crypto';
import { WebView } from 'react-native-webview';
import { Button, Field, s, signatureHtml } from './ui';
import { Decision, File, Message, Profile, RequestItem, Thread } from './types';

const API = String(Constants.expoConfig?.extra?.apiUrl || '').replace(/\/+$/, '');
const SESSION = 'procura-chat-session';
const pretty = (text: string) => text.replaceAll('_', ' ').toLowerCase().replace(/^\w/, c => c.toUpperCase());
Notifications.setNotificationHandler({ handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }) });

export default function App() {
    const [token, setToken] = useState(''), [profile, setProfile] = useState<Profile | null>(null);
    const [ready, setReady] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState('');
    const [login, setLogin] = useState(''), [password, setPassword] = useState(''), [newPassword, setNewPassword] = useState(''), [confirmation, setConfirmation] = useState('');
    const [rows, setRows] = useState<RequestItem[]>([]), [search, setSearch] = useState(''), [filter, setFilter] = useState('all');
    const [page, setPage] = useState(1), [lastPage, setLastPage] = useState(1);
    const [selected, setSelected] = useState<number | null>(null), [thread, setThread] = useState<Thread | null>(null);
    const [messages, setMessages] = useState<Message[]>([]), [hasOlder, setHasOlder] = useState(false);
    const [body, setBody] = useState(''), [upload, setUpload] = useState<DocumentPicker.DocumentPickerAsset | null>(null);
    const [connected, setConnected] = useState(false), [notificationStatus, setNotificationStatus] = useState('');
    const [details, setDetails] = useState(false), [rolePicker, setRolePicker] = useState(false);
    const [decision, setDecision] = useState<Decision | null>(null), [quoteId, setQuoteId] = useState<number | null>(null);
    const [remarks, setRemarks] = useState(''), [signature, setSignature] = useState(''), [signatureKey, setSignatureKey] = useState(0), [decisionError, setDecisionError] = useState('');
    const threadInFlight = useRef<number | null>(null), hydrated = useRef<number | null>(null);
    const generation = useRef(0), cursor = useRef(0), sending = useRef(false), pendingId = useRef<string | null>(null);
    const listRef = useRef<FlatList<Message>>(null), pendingNotification = useRef<number | null>(null);
    const tokenRef = useRef(''), selectedRef = useRef<number | null>(null), scrollToBottom = useRef(true);
    tokenRef.current = token; selectedRef.current = selected;

    const clearThread = useCallback(() => {
        generation.current++; cursor.current = 0; selectedRef.current = null; scrollToBottom.current = true;
        setSelected(null); setThread(null); setMessages([]); setDetails(false); setDecision(null); setBody(''); setUpload(null); pendingId.current = null;
    }, []);
    const localSignOut = useCallback(async () => {
        await SecureStore.deleteItemAsync(SESSION);
        setToken(''); tokenRef.current = ''; setProfile(null); setRows([]); clearThread();
    }, [clearThread]);
    const api = useCallback(async (path: string, options: RequestInit = {}, session = tokenRef.current): Promise<any> => {
        if (!API || (!__DEV__ && !API.startsWith('https://'))) throw new Error('Configure the HTTPS PROCURA API URL for this build.');
        const response = await fetch(API + path, { ...options, headers: { Accept: 'application/json', ...(session ? { Authorization: 'Bearer ' + session } : {}), ...options.headers } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 401) await localSignOut();
            if (response.status === 403) clearThread();
            if (response.status === 423) setProfile(p => p ? { ...p, must_change_password: true } : p);
            throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to connect. Please try again.');
        }
        return data;
    }, [clearThread, localSignOut]);
    const post = useCallback((path: string, data: object, session?: string) => api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }, session), [api]);

    useEffect(() => {
        (async () => {
            try {
                const stored = await SecureStore.getItemAsync(SESSION);
                if (stored) { tokenRef.current = stored; const user = await api('/me', {}, stored); setToken(stored); setProfile(user); }
            } catch(e) { setError((e as Error).message); }
            finally { setReady(true); }
        })();
    }, [api]);
    useEffect(() => {
        const response = (value: Notifications.NotificationResponse) => {
            const id = Number(value.notification.request.content.data?.requestId);
            if (!Number.isInteger(id) || id < 1) return;
            pendingNotification.current = id;
            if (tokenRef.current) { clearThread(); selectedRef.current = id; setSelected(id); }
        };
        const subscription = Notifications.addNotificationResponseReceivedListener(response);
        Notifications.getLastNotificationResponseAsync().then(value => { if (value) response(value); });
        return () => subscription.remove();
    }, [clearThread]);
    async function registerPush() {
        if (!Device.isDevice) { setNotificationStatus('Phone notifications require a physical device.'); return; }
        try {
            if (Platform.OS === 'android') await Notifications.setNotificationChannelAsync('procura-approvals', { name: 'PROCURA approvals and updates', importance: Notifications.AndroidImportance.HIGH, sound: 'default', vibrationPattern: [0, 250, 250, 250] });
            let permission = await Notifications.getPermissionsAsync();
            if (!permission.granted) permission = await Notifications.requestPermissionsAsync();
            if (!permission.granted) { setNotificationStatus('Notifications are off. Enable them in phone settings for alerts and sound.'); return; }
            const projectId = Constants.expoConfig?.extra?.eas?.projectId;
            if (!projectId) { setNotificationStatus('Push notifications need an EAS project configured in this app build.'); return; }
            const result = await Notifications.getExpoPushTokenAsync({ projectId });
            await post('/devices', { push_token: result.data, platform: Platform.OS }); setNotificationStatus('');
        } catch { setNotificationStatus('Could not register phone alerts. Tap to retry.'); }
    }
    useEffect(() => { if (token && profile && !profile.must_change_password) void registerPush(); }, [token, profile?.must_change_password]);

    const loadList = useCallback(async () => {
        const data = await api('/requests?' + new URLSearchParams({ search, filter, page: String(page) }).toString());
        setRows(data.data); setLastPage(data.last_page);
    }, [api, search, filter, page]);
    const loadThread = useCallback(async (initial = false) => {
        if (!selected) return;
        const id = selected, version = generation.current;
        if (threadInFlight.current === version) return;
        initial ||= hydrated.current !== version;
        threadInFlight.current = version;
        let data: Thread;
        try { data = await api('/requests/' + id + (initial ? '' : '?after=' + cursor.current)); }
        finally { if (threadInFlight.current === version) threadInFlight.current = null; }
        if (generation.current !== version || selectedRef.current !== id) return;
        hydrated.current = version;
        setThread(data);
        if (initial) { setMessages(data.messages); setHasOlder(data.has_more); }
        else if (data.messages.length) setMessages(old => { const ids = new Set(old.map(m => m.id)); return [...old, ...data.messages.filter(m => !ids.has(m.id))]; });
        cursor.current = data.messages.at(-1)?.id || cursor.current;
        if (cursor.current) await post('/requests/' + id + '/read', { message_id: cursor.current });
    }, [selected, api, post]);
    useEffect(() => {
        if (!token || !profile || profile.must_change_password) return;
        let stopped = false, active = false;
        const tick = async () => {
            if (active || AppState.currentState !== 'active' || stopped) return; active = true;
            try { await loadList(); if (!stopped) await loadThread(); if (!stopped) setConnected(true); }
            catch(e) { if (!stopped) { setConnected(false); setError((e as Error).message); } }
            finally { active = false; }
        };
        void tick(); const timer = setInterval(tick, 4000);
        const listener = AppState.addEventListener('change', state => { if (state === 'active') void tick(); });
        return () => { stopped = true; clearInterval(timer); listener.remove(); };
    }, [token, profile?.must_change_password, profile?.role_id, loadList, loadThread]);
    useEffect(() => { if (selected && token) loadThread(true).catch(e => setError(e.message)); }, [selected, token, loadThread]);

    useEffect(() => {
        if (!selected) return;
        const listener = BackHandler.addEventListener('hardwareBackPress', () => { clearThread(); return true; });
        return () => listener.remove();
    }, [selected, clearThread]);

    async function signIn() {
        setBusy(true); setError('');
        try {
            const data = await post('/login', { login, password, device_name: Device.modelName || Platform.OS }, '');
            await SecureStore.setItemAsync(SESSION, data.token);
            tokenRef.current = data.token; setToken(data.token); setProfile(data.user); setPassword('');
            if (pendingNotification.current) { selectedRef.current = pendingNotification.current; setSelected(pendingNotification.current); }
        } catch(e) { setError((e as Error).message); } finally { setBusy(false); }
    }
    async function changePassword() {
        setBusy(true); setError('');
        try {
            await post('/password', { current_password: password, password: newPassword, password_confirmation: confirmation });
            setProfile(await api('/me')); setPassword(''); setNewPassword(''); setConfirmation('');
        } catch(e) { setError((e as Error).message); } finally { setBusy(false); }
    }
    async function signOut() {
        setBusy(true);
        try { await post('/logout', {}); await localSignOut(); }
        catch { setError('Reconnect and try again to sign out and revoke this phone session.'); } finally { setBusy(false); }
    }
    function openRequest(id: number) { clearThread(); selectedRef.current = id; setSelected(id); setError(''); setQuoteId(null); }
    async function sendMessage() {
        if (!selected || (!body.trim() && !upload) || sending.current) return;
        sending.current = true; setBusy(true); const id = selected;
        try {
            pendingId.current ||= Crypto.randomUUID();
            const form = new FormData(); form.append('body', body.trim()); form.append('client_id', pendingId.current);
            if (upload) form.append('attachment', { uri: upload.uri, name: upload.name, type: upload.mimeType || 'application/octet-stream' } as any);
            await api('/requests/' + id + '/messages', { method: 'POST', body: form });
            if (selectedRef.current === id) { setBody(''); setUpload(null); pendingId.current = null; scrollToBottom.current = true; await loadThread(); }
            setError('');
        } catch(e) { setError((e as Error).message); } finally { sending.current = false; setBusy(false); }
    }
    async function pickFile() {
        try {
            const result = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], copyToCacheDirectory: true });
            if (!result.canceled) {
                const file = result.assets[0]; if ((file.size || 0) > 10 * 1024 * 1024) throw new Error('Attachments must be 10 MB or smaller.');
                setUpload(file); pendingId.current = null;
            }
        } catch(e) { setError((e as Error).message); }
    }
    async function download(file: File) {
        try {
            if (!file.url.startsWith(API + '/')) throw new Error('Invalid attachment location.');
            if (!FileSystem.cacheDirectory) throw new Error('Temporary storage is unavailable.');
            const uri = FileSystem.cacheDirectory + Date.now() + '-' + file.name.replace(/[^a-zA-Z0-9._-]/g, '_');
            const result = await FileSystem.downloadAsync(file.url, uri, { headers: { Authorization: 'Bearer ' + tokenRef.current, Accept: 'application/octet-stream' } });
            try {
                if (result.status !== 200) throw new Error('You cannot download this attachment.');
                if (!(await Sharing.isAvailableAsync())) throw new Error('File sharing is unavailable on this device.');
                await Sharing.shareAsync(result.uri);
            } finally { await FileSystem.deleteAsync(uri, { idempotent: true }); }
        } catch(e) { setError((e as Error).message); }
    }
    function beginDecision(value: Decision) {
        if (value === 'president-approve' && !quoteId) { setDetails(true); setError('Select a supplier quotation before approving.'); return; }
        setDecision(value); setRemarks(''); setSignature(''); setSignatureKey(key => key + 1); setDecisionError('');
    }
    async function confirmDecision() {
        if (!selected || !decision || sending.current) return;
        if (!signature) { setDecisionError('Draw your signature before confirming.'); return; }
        if (decision.endsWith('return') && !remarks.trim()) { setDecisionError('Explain the correction needed.'); return; }
        sending.current = true; setBusy(true); const id = selected;
        try {
            const data: Record<string, unknown> = { remarks, signature_name: profile?.name, signature_data: signature };
            let path: string;
            if (decision.startsWith('om-')) path = '/requests/' + id + '/om/' + (decision === 'om-approve' ? 'approve' : 'return');
            else { path = '/requests/' + id + '/president'; data.decision = decision === 'president-approve' ? 'APPROVE' : decision === 'president-return' ? 'REJECT' : 'ACKNOWLEDGE'; if (quoteId) data.quotation_id = quoteId; }
            await post(path, data); setDecision(null); await loadThread(); await loadList(); setError('');
        } catch(e) { setDecisionError((e as Error).message); } finally { sending.current = false; setBusy(false); }
    }
    async function acknowledge() {
        if (!selected || !thread) return; setBusy(true);
        try { await post('/requests/' + selected + '/acknowledge', { message_id: thread.workflow_message_id }); await loadThread(); }
        catch(e) { setError((e as Error).message); } finally { setBusy(false); }
    }
    async function older() {
        if (!selected || !messages.length) return;
        const version = generation.current; scrollToBottom.current = false;
        try {
            const data: Thread = await api('/requests/' + selected + '?before=' + messages[0].id);
            if (generation.current !== version) return;
            setMessages(old => [...data.messages, ...old]); setHasOlder(data.has_more);
        } catch(e) { setError((e as Error).message); }
    }
    const fileView = (file: File) => <Pressable key={file.url} onPress={() => download(file)}><Text style={s.file}>↓ {file.name}</Text></Pressable>;
    if (!ready) return <SafeAreaView style={s.center}><ActivityIndicator color="#171f70" /></SafeAreaView>;
    return <SafeAreaView style={s.safe}><KeyboardAvoidingView style={s.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={s.header}><Image source={require('./assets/logo.png')} style={s.logo} resizeMode="contain" />
            {profile && <Pressable onPress={() => setRolePicker(true)}><Text style={s.role}>{profile.role.toUpperCase()} ▾</Text></Pressable>}</View>
        {!!error && <Pressable onPress={() => setError('')}><Text accessibilityRole="alert" style={s.error}>{error}  ×</Text></Pressable>}
        {!token || !profile ? <ScrollView contentContainerStyle={s.login} keyboardShouldPersistTaps="handled">
            <Text style={s.eyebrow}>YOUR WORKFLOW, CONNECTED</Text><Text style={s.hero}>Welcome to{'\n'}Procura Chat.</Text>
            <Text style={s.muted}>Review requests. Keep the conversation moving.</Text>
            {!API && <Text style={s.error}>Configure the server’s HTTPS API URL before building this app.</Text>}
            <Field placeholder="Email or username" value={login} onChangeText={setLogin} autoComplete="username" />
            <Field placeholder="Password" value={password} onChangeText={setPassword} secureTextEntry autoComplete="current-password" />
            <Button title={busy ? 'Signing in…' : 'Sign in →'} onPress={signIn} disabled={busy || !login || !password || !API} />
            <Text style={s.note}>Use your admin-authorized ASI PROCURA account.</Text>
        </ScrollView> : profile.must_change_password ? <ScrollView contentContainerStyle={s.login}>
            <Text style={s.hero}>Make it yours.</Text><Text style={s.muted}>Replace your temporary password to continue.</Text>
            <Field placeholder="Current password" value={password} onChangeText={setPassword} secureTextEntry />
            <Field placeholder="New password (at least 8 characters)" value={newPassword} onChangeText={setNewPassword} secureTextEntry />
            <Field placeholder="Confirm new password" value={confirmation} onChangeText={setConfirmation} secureTextEntry />
            <Button title="Save password" onPress={changePassword} disabled={busy} /><Button title="Sign out" onPress={signOut} secondary disabled={busy} />
        </ScrollView> : !selected ? <>
            <View style={s.headingRow}><View><Text style={s.heading}>Procura Chats</Text><Text style={s.muted}>{profile.campaign || 'Request conversations'}</Text></View><Text style={connected ? s.online : s.offline}>{connected ? '● Live' : '● Offline'}</Text></View>
            {!!notificationStatus && <Pressable onPress={() => Alert.alert('Phone notifications', notificationStatus, [{ text: 'Retry', onPress: registerPush }, { text: 'Settings', onPress: () => Linking.openSettings() }, { text: 'Close' }])}><Text style={s.notice}>{notificationStatus}</Text></Pressable>}
            <View style={s.controls}><Field placeholder="Search request number or title…" value={search} onChangeText={value => { setSearch(value); setPage(1); }} />
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.filters}>{[['all', 'All'], ['unread', 'Unread'], ['mine', 'My requests'], ['approval', 'For approval']].map(([key, title]) =>
                    <Pressable key={key} onPress={() => { setFilter(key); setPage(1); }} style={[s.pill, filter === key && s.pillActive]}><Text style={[s.pillText, filter === key && s.pillTextActive]}>{title}</Text></Pressable>)}</ScrollView></View>
            <FlatList data={rows} keyExtractor={r => String(r.id)} onRefresh={() => loadList().catch(e => setError(e.message))} refreshing={busy}
                ListEmptyComponent={<View style={s.empty}><Text style={s.muted}>No requests found.</Text></View>}
                renderItem={({ item }) => <Pressable onPress={() => openRequest(item.id)} style={s.row}><View style={s.avatar}><Text style={s.avatarText}>PR</Text></View>
                    <View style={s.flex}><Text style={s.requestNumber}>{item.request_number}</Text><Text numberOfLines={1} style={s.preview}>{item.last_message || item.title}</Text></View>
                    {!!item.unread_count && <Text style={s.unread}>{item.unread_count}</Text>}</Pressable>} />
            <View style={s.pages}><Button title="Previous" onPress={() => setPage(value => value - 1)} secondary disabled={page <= 1} /><Text style={s.note}>{page} / {lastPage}</Text><Button title="Next" onPress={() => setPage(value => value + 1)} secondary disabled={page >= lastPage} /></View>
        </> : <>
            <View style={s.threadHeader}><Pressable onPress={clearThread} style={s.back}><Text style={s.backText}>←</Text></Pressable><View style={s.flex}><Text style={s.requestNumber}>{thread?.request.request_number || 'Loading…'}</Text><Text numberOfLines={1} style={s.preview}>{thread?.request.title}</Text></View><Pressable onPress={() => setDetails(value => !value)}><Text style={s.role}>{details ? 'Chat' : 'Details'}</Text></Pressable></View>
            {thread && <Text style={s.stage}>{pretty(thread.request.status)}</Text>}
            {details && thread ? <ScrollView style={s.details}><Text style={s.requestNumber}>{thread.request.requestor} · {thread.request.campaign}</Text><Text style={s.muted}>{thread.request.team} · {thread.request.category} · ₱{thread.request.total_amount}</Text>
                <Text style={s.preview}>Purpose: {thread.request.purpose}</Text>
                <Text style={s.preview}>Required: {thread.request.required_date || 'Not specified'} · Deliver to: {thread.request.delivery_location || 'Not specified'}</Text>
                {thread.request.items.map(item => <View key={item.id} style={s.item}><Text>{item.description} · {item.quantity} {item.unit}</Text>{!!item.brand_specifications && <Text style={s.preview}>{item.brand_specifications}</Text>}{item.files.map(fileView)}</View>)}
                {thread.request.attachments.map(fileView)}<Text style={s.sectionTitle}>Supplier quotations</Text>
                {thread.request.quotations.map(quote => <Pressable key={quote.id} onPress={() => setQuoteId(quote.id)} style={[s.quote, quoteId === quote.id && s.quoteSelected]}>
                    <Text style={s.requestNumber}>{quoteId === quote.id ? '● ' : '○ '}{quote.supplier} · ₱{quote.total}</Text>
                    {quote.is_recommended && <Text style={s.recommended}>Purchasing recommendation</Text>}
                    <Text style={s.preview}>{[quote.item, quote.delivery_terms, quote.warranty, quote.remarks].filter(Boolean).join(' · ')}</Text>{quote.files.map(fileView)}</Pressable>)}
                {!thread.request.quotations.length && <Text style={s.muted}>No supplier quotations yet.</Text>}
            </ScrollView> : <FlatList ref={listRef} data={messages} keyExtractor={m => String(m.id)} style={s.messageList}
                contentContainerStyle={s.messageContent} onContentSizeChange={() => { if (scrollToBottom.current) listRef.current?.scrollToEnd({ animated: true }); }}
                onScroll={event => { const { layoutMeasurement, contentOffset, contentSize } = event.nativeEvent; scrollToBottom.current = contentSize.height - layoutMeasurement.height - contentOffset.y < 80; }}
                ListHeaderComponent={hasOlder && messages.length ? <Button title="Earlier messages" onPress={older} secondary /> : undefined}
                ListEmptyComponent={<Text style={s.note}>Updates and messages will appear here.</Text>}
                renderItem={({ item }) => <View style={[s.message, item.user_id === profile.id && s.mine, item.kind !== 'message' && s.system]}>
                    {item.kind === 'message' && <Text style={s.sender}>{item.user_name}</Text>}
                    <Text style={item.kind !== 'message' ? s.systemText : s.messageText}>{item.body}</Text>
                    {item.attachment && fileView(item.attachment)}<Text style={s.time}>{new Date(item.created_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</Text>
                </View>} />}
            <View style={s.actions}>
                {thread?.permissions.approve_om && <><Button title="Approve → Screening" onPress={() => beginDecision('om-approve')} disabled={busy} /><Button title="Return for correction" onPress={() => beginDecision('om-return')} secondary disabled={busy} /></>}
                {thread?.permissions.approve_president && <><Button title="Approve supplier" onPress={() => beginDecision('president-approve')} disabled={busy} /><Button title="Return to canvassing" onPress={() => beginDecision('president-return')} secondary disabled={busy} />{!thread.request.quotations.length && <Button title="Acknowledge without pricing" onPress={() => beginDecision('president-acknowledge')} secondary disabled={busy} />}</>}
                {thread?.permissions.acknowledge && <Button title={thread.acknowledged ? '✓ SMT acknowledged' : 'Acknowledge update (optional)'} onPress={acknowledge} secondary disabled={busy || thread.acknowledged} />}
            </View>
            {thread?.permissions.send ? <View><View style={s.compose}><Pressable onPress={pickFile} accessibilityLabel="Attach PDF or image"><Text style={s.attach}>＋</Text></Pressable>
                <View style={s.flex}><Field placeholder="Type a message…" multiline maxLength={5000} value={body} onChangeText={value => { setBody(value); pendingId.current = null; }} /></View>
                <Button title="↗" onPress={sendMessage} disabled={busy} /></View>{upload && <Pressable onPress={() => setUpload(null)}><Text style={s.note}>{upload.name} · Tap to remove</Text></Pressable>}</View> : <Text style={s.readonly}>Read-only conversation for your current role.</Text>}
        </>}
        <Modal visible={!!decision} animationType="slide" onRequestClose={() => { if (!busy) setDecision(null); }}>
            <SafeAreaView style={s.safe}><ScrollView contentContainerStyle={s.modal} keyboardShouldPersistTaps="handled">
                <Text style={s.heading}>{decision?.endsWith('return') ? 'Return for correction' : decision?.endsWith('acknowledge') ? 'Acknowledge request' : 'Approve request'}</Text>
                <Text style={s.muted}>Signing as {profile?.name}. Your decision updates ASI PROCURA.</Text>
                <Field placeholder={decision?.endsWith('return') ? 'Correction needed (required)' : 'Remarks (optional)'} multiline value={remarks} onChangeText={setRemarks} maxLength={2000} />
                <Text style={s.sectionTitle}>Draw your signature</Text>
                <View style={s.signature}><WebView key={signatureKey} source={{ html: signatureHtml }} originWhitelist={['*']} scrollEnabled={false} javaScriptEnabled
                    onShouldStartLoadWithRequest={request => request.url === 'about:blank'} onMessage={event => { if (event.nativeEvent.data.startsWith('data:image/png;base64,')) setSignature(event.nativeEvent.data); }} /></View>
                <Button title="Clear signature" onPress={() => { setSignature(''); setSignatureKey(key => key + 1); }} secondary />
                {!!decisionError && <Text style={s.error}>{decisionError}</Text>}
                <Button title={busy ? 'Recording…' : 'Sign & confirm'} onPress={confirmDecision} disabled={busy} /><Button title="Cancel" onPress={() => setDecision(null)} secondary disabled={busy} />
            </ScrollView></SafeAreaView>
        </Modal>
        <Modal visible={rolePicker} transparent animationType="fade" onRequestClose={() => setRolePicker(false)}>
            <View style={s.overlay}><View style={s.rolePanel}><Text style={s.heading}>{profile?.name}</Text><Text style={s.muted}>Choose your assigned workspace</Text>
                {profile?.roles.map(role => <Button key={role.id} title={role.name} secondary={role.id !== profile.role_id} onPress={async () => {
                    try { setProfile(await post('/role', { role_id: role.id })); clearThread(); setRows([]); setFilter('all'); setPage(1); setRolePicker(false); }
                    catch(e) { setError((e as Error).message); }
                }} />)}
                <Button title="Sign out" onPress={() => { setRolePicker(false); void signOut(); }} secondary disabled={busy} /><Button title="Close" onPress={() => setRolePicker(false)} secondary />
            </View></View>
        </Modal>
    </KeyboardAvoidingView></SafeAreaView>;
}
