import React from 'react';
import { Pressable, StyleSheet, Text, TextInput } from 'react-native';

export function Button({ title, onPress, secondary = false, disabled = false }: { title: string; onPress: () => void; secondary?: boolean; disabled?: boolean }) {
    return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[s.button, secondary && s.secondary, disabled && s.disabled]}><Text style={[s.buttonText, secondary && s.secondaryText]}>{title}</Text></Pressable>;
}
export function Field(props: React.ComponentProps<typeof TextInput>) {
    return <TextInput placeholderTextColor="#9299ac" style={s.input} autoCapitalize="none" {...props} />;
}
export const signatureHtml = '<!doctype html><html><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"><style>body{margin:0;background:white}canvas{width:100%;height:180px;touch-action:none}</style><canvas width="600" height="180"></canvas><script>const c=document.querySelector("canvas"),x=c.getContext("2d");let down=false,moved=false;function p(e){const r=c.getBoundingClientRect();return [(e.clientX-r.left)*600/r.width,(e.clientY-r.top)*180/r.height]}c.onpointerdown=e=>{down=true;moved=false;c.setPointerCapture(e.pointerId);x.beginPath();x.moveTo(...p(e))};c.onpointermove=e=>{if(!down)return;x.strokeStyle="#171f70";x.lineWidth=3;x.lineCap="round";x.lineTo(...p(e));x.stroke();moved=true};c.onpointerup=()=>{down=false;if(moved)window.ReactNativeWebView.postMessage(c.toDataURL("image/png"))};c.onpointercancel=()=>down=false;<\/script></html>';

export const s = StyleSheet.create({
    safe: { flex: 1, backgroundColor: '#fff' }, flex: { flex: 1 }, center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#fff' },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 22, paddingVertical: 14, borderBottomWidth: 1, borderColor: '#eef0f7' },
    logo: { width: 148, height: 34 }, role: { color: '#171f70', fontSize: 11, fontWeight: '700', padding: 8 },
    login: { padding: 28, paddingTop: 55, gap: 16 }, hero: { fontSize: 38, fontWeight: '800', color: '#172043', letterSpacing: -1.3, lineHeight: 44 },
    eyebrow: { fontSize: 10, color: '#f48a19', letterSpacing: 2, fontWeight: '700' }, muted: { fontSize: 12, color: '#828ba3', lineHeight: 19 },
    input: { backgroundColor: '#f5f6fa', borderWidth: 1, borderColor: '#e9ecf4', borderRadius: 12, padding: 14, color: '#19213d', fontSize: 14 },
    button: { backgroundColor: '#171f70', borderRadius: 11, paddingHorizontal: 16, paddingVertical: 12, alignItems: 'center' },
    buttonText: { color: 'white', fontWeight: '700', fontSize: 12 }, secondary: { backgroundColor: '#f2f3fa' }, secondaryText: { color: '#171f70' }, disabled: { opacity: .45 },
    headingRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', padding: 22 }, heading: { fontSize: 26, fontWeight: '800', color: '#172043', letterSpacing: -.8 },
    online: { color: '#2e8a68', fontSize: 10 }, offline: { color: '#9b8d74', fontSize: 10 }, controls: { paddingHorizontal: 20, gap: 14, paddingBottom: 12 },
    filters: { gap: 7 }, pill: { paddingVertical: 8, paddingHorizontal: 15, backgroundColor: '#f2f3f8', borderRadius: 20 }, pillActive: { backgroundColor: '#171f70' },
    pillText: { fontSize: 11, fontWeight: '700', color: '#69738e' }, pillTextActive: { color: '#fff' },
    row: { flexDirection: 'row', alignItems: 'center', gap: 13, paddingHorizontal: 22, paddingVertical: 18, borderBottomWidth: 1, borderColor: '#f5f6fa' },
    avatar: { width: 44, height: 44, borderRadius: 22, backgroundColor: '#eaf6ff', alignItems: 'center', justifyContent: 'center' }, avatarText: { color: '#187fb9', fontSize: 12, fontWeight: '800' },
    requestNumber: { color: '#202842', fontSize: 14, fontWeight: '700', marginBottom: 5 }, preview: { color: '#7c869f', fontSize: 12, lineHeight: 18 },
    unread: { backgroundColor: '#f48a19', color: '#fff', borderRadius: 15, paddingHorizontal: 6, paddingVertical: 2, fontSize: 11, overflow: 'hidden' },
    pages: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: 12, borderTopWidth: 1, borderColor: '#eef0f7' },
    note: { color: '#939bb0', fontSize: 11, textAlign: 'center', padding: 8 }, empty: { padding: 45, alignItems: 'center' }, error: { color: '#a03d3d', backgroundColor: '#fff0ed', padding: 12, fontSize: 12, lineHeight: 18 },
    notice: { color: '#896935', backgroundColor: '#fff8e9', padding: 12, fontSize: 11 }, threadHeader: { flexDirection: 'row', gap: 12, alignItems: 'center', padding: 14 },
    back: { padding: 8 }, backText: { color: '#171f70', fontSize: 25 }, stage: { fontSize: 10, color: '#326bc0', backgroundColor: '#f0f5ff', paddingHorizontal: 22, paddingVertical: 10 },
    details: { flex: 1, padding: 20 }, item: { paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eef0f7' }, sectionTitle: { fontSize: 13, color: '#19213d', fontWeight: '700', marginVertical: 16 },
    quote: { padding: 15, backgroundColor: '#f6f7fc', borderRadius: 12, marginBottom: 12, borderWidth: 1, borderColor: '#e9ecf4' }, quoteSelected: { borderColor: '#171f70', backgroundColor: '#eeedff' },
    recommended: { color: '#398d72', fontSize: 10, marginBottom: 6 }, file: { color: '#283a95', fontSize: 12, paddingVertical: 9 },
    messageList: { flex: 1, backgroundColor: '#fcfcff' }, messageContent: { padding: 18, gap: 18 }, message: { alignSelf: 'flex-start', maxWidth: '88%', backgroundColor: '#f0f2f8', borderRadius: 14, padding: 13 },
    mine: { alignSelf: 'flex-end', backgroundColor: '#e8e7ff' }, sender: { color: '#7b84a0', fontSize: 10, marginBottom: 5, fontWeight: '700' },
    messageText: { color: '#28304a', fontSize: 14, lineHeight: 21 }, time: { color: '#939bb0', fontSize: 9, textAlign: 'right', marginTop: 7 },
    system: { alignSelf: 'center', backgroundColor: '#f3f6fb', maxWidth: '95%' }, systemText: { fontSize: 11, color: '#7885a3', textAlign: 'center', lineHeight: 18 },
    actions: { gap: 7, paddingHorizontal: 14, paddingVertical: 5 }, compose: { flexDirection: 'row', alignItems: 'center', gap: 9, padding: 12, borderTopWidth: 1, borderColor: '#eef0f7' },
    attach: { color: '#7c86a0', fontSize: 28 }, composerInput: { flex: 1, maxHeight: 100, borderWidth: 1, borderColor: '#e3e7f0', borderRadius: 11, padding: 12, fontSize: 13 },
    readonly: { textAlign: 'center', padding: 14, color: '#8f97ac', fontSize: 11 }, modal: { padding: 25, gap: 14 }, signature: { height: 184, borderWidth: 1, borderStyle: 'dashed', borderColor: '#b8c0d7', borderRadius: 10, overflow: 'hidden' },
    overlay: { flex: 1, backgroundColor: '#10183b88', justifyContent: 'center', padding: 25 }, rolePanel: { backgroundColor: '#fff', borderRadius: 20, padding: 25, gap: 12 },
});
