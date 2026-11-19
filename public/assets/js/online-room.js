/* DarsPlus – the online lesson room (LiveKit). The server decides who enters and with what rights
   (the access token); the teacher's controls (mute, remove, lock, allow screen) are carried out by the
   server too, so nothing here can be bypassed from the browser. Chat and raised hands travel as data
   messages between the participants and are not kept. */
(function () {
    'use strict';

    var root = document.getElementById('meet');
    if (!root || !window.LivekitClient) {
        return;
    }
    var LK = window.LivekitClient;
    var Track = LK.Track;
    var RoomEvent = LK.RoomEvent;
    var T = {};
    try {
        T = JSON.parse(root.dataset.i18n || '{}');
    } catch (error) {
        T = {};
    }
    var t = function (key, values) {
        var text = T[key] || key;
        Object.keys(values || {}).forEach(function (name) {
            text = text.split(':' + name).join(values[name]);
        });
        return text;
    };
    var $ = function (id) { return document.getElementById(id); };

    var isHost = root.dataset.role === 'host';
    var myIdentity = root.dataset.identity;
    var room = null;
    var hands = {};              // identity => true while the hand is up
    var screenAllowed = {};      // (teacher) identity => true when allowed to share
    var micsLocked = false;
    var leaving = false;
    var unread = 0;
    var encoder = new TextEncoder();
    var decoder = new TextDecoder();

    // ---- Small helpers ------------------------------------------------------

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function icon(name) {
        var i = el('i', 'bi bi-' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function toast(text, kind) {
        var box = el('div', 'meet-toast' + (kind ? ' meet-toast-' + kind : ''), text);
        box.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
        $('meet-toasts').appendChild(box);
        setTimeout(function () { box.classList.add('is-leaving'); }, 4200);
        setTimeout(function () { box.remove(); }, 4800);
    }

    function roleOf(participant) {
        try {
            return JSON.parse(participant.metadata || '{}').role || 'student';
        } catch (error) {
            return 'student';
        }
    }

    function nameOf(participant) {
        return participant.name || participant.identity;
    }

    function initials(name) {
        var parts = String(name).trim().split(/\s+/);
        return (parts[0] ? parts[0].charAt(0) : '?') + (parts[1] ? parts[1].charAt(0) : '');
    }

    function everyone() {
        if (!room) {
            return [];
        }
        var list = [room.localParticipant];
        room.remoteParticipants.forEach(function (p) { list.push(p); });
        // The teacher first, then by name.
        return list.sort(function (a, b) {
            var ra = roleOf(a) === 'host' ? 0 : 1;
            var rb = roleOf(b) === 'host' ? 0 : 1;
            return ra !== rb ? ra - rb : nameOf(a).localeCompare(nameOf(b));
        });
    }

    function publication(participant, source) {
        var pub = participant.getTrackPublication(source);
        return pub && pub.track && !pub.isMuted ? pub : null;
    }

    function may(source) {
        // An empty list of sources means every source is allowed.
        var perms = room && room.localParticipant.permissions;
        if (!perms || !perms.canPublishSources || perms.canPublishSources.length === 0) {
            return true;
        }
        var numbers = { camera: 1, microphone: 2, screen_share: 3 };
        return perms.canPublishSources.indexOf(numbers[source]) !== -1;
    }

    function send(topic, data, to) {
        if (!room) {
            return;
        }
        var options = { reliable: true, topic: topic };
        if (to) {
            options.destinationIdentities = [to];
        }
        room.localParticipant.publishData(encoder.encode(JSON.stringify(data)), options).catch(function () {});
    }

    function moderate(action, identity) {
        var body = new URLSearchParams();
        body.set('_csrf', root.dataset.csrf);
        body.set('action', action);
        if (identity) {
            body.set('identity', identity);
        }
        return fetch(root.dataset.moderateUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok) {
                        throw new Error(data.error || t('action_failed'));
                    }
                    return data;
                });
            })
            .catch(function (error) {
                toast(error.message || t('action_failed'), 'danger');
                throw error;
            });
    }

    // ---- Tiles ----------------------------------------------------------------

    function tileFor(participant) {
        var grid = $('meet-grid');
        var tile = grid.querySelector('[data-identity="' + CSS.escape(participant.identity) + '"]');
        if (tile) {
            return tile;
        }
        tile = el('figure', 'meet-tile');
        tile.dataset.identity = participant.identity;
        var video = el('video');
        video.autoplay = true;
        video.playsInline = true;
        video.muted = true;
        var avatar = el('div', 'meet-avatar', initials(nameOf(participant)));
        avatar.setAttribute('aria-hidden', 'true');
        var caption = el('figcaption', 'meet-name');
        var micState = el('span', 'meet-mic-state');
        micState.appendChild(icon('mic-mute-fill'));
        var label = el('span', 'meet-name-text', nameOf(participant));
        caption.appendChild(micState);
        caption.appendChild(label);
        var hand = el('span', 'meet-hand');
        hand.appendChild(icon('hand-index-thumb-fill'));
        hand.hidden = true;
        tile.appendChild(video);
        tile.appendChild(avatar);
        tile.appendChild(caption);
        tile.appendChild(hand);
        grid.appendChild(tile);
        return tile;
    }

    function renderTile(participant) {
        var tile = tileFor(participant);
        var local = participant === room.localParticipant;
        var role = roleOf(participant);
        var label = tile.querySelector('.meet-name-text');
        label.textContent = nameOf(participant) + (local ? ' (' + t('you') + ')' : '') + (role === 'host' ? ' · ' + t('host') : '');
        tile.classList.toggle('is-host', role === 'host');
        tile.classList.toggle('is-local', local);

        var cam = publication(participant, Track.Source.Camera);
        var video = tile.querySelector('video');
        if (cam) {
            if (video.dataset.trackSid !== cam.trackSid) {
                cam.track.attach(video);
                video.dataset.trackSid = cam.trackSid;
            }
            tile.classList.add('has-video');
        } else {
            if (video.dataset.trackSid) {
                video.srcObject = null;
                delete video.dataset.trackSid;
            }
            tile.classList.remove('has-video');
        }
        var mic = publication(participant, Track.Source.Microphone);
        tile.querySelector('.meet-mic-state').hidden = !!mic;
        tile.classList.toggle('is-speaking', participant.isSpeaking && !!mic);
        tile.querySelector('.meet-hand').hidden = !hands[participant.identity];
        tile.classList.toggle('has-hand', !!hands[participant.identity]);
    }

    function renderSpotlight() {
        var presenter = null;
        var pub = null;
        everyone().forEach(function (p) {
            var screen = publication(p, Track.Source.ScreenShare);
            if (screen && !presenter) {
                presenter = p;
                pub = screen;
            }
        });
        var spot = $('meet-spotlight');
        var video = $('meet-screen');
        if (pub) {
            if (video.dataset.trackSid !== pub.trackSid) {
                pub.track.attach(video);
                video.dataset.trackSid = pub.trackSid;
            }
            $('meet-spotlight-label').textContent = t('presenting', { name: nameOf(presenter) });
            spot.hidden = false;
        } else {
            video.srcObject = null;
            delete video.dataset.trackSid;
            spot.hidden = true;
        }
        $('meet-stage').classList.toggle('has-spotlight', !!pub);
    }

    function renderPeople() {
        var list = $('meet-people-list');
        list.textContent = '';
        everyone().forEach(function (p) {
            var local = p === room.localParticipant;
            var item = el('li', 'meet-person');
            var avatar = el('span', 'meet-person-avatar', initials(nameOf(p)));
            avatar.setAttribute('aria-hidden', 'true');
            var text = el('span', 'meet-person-name', nameOf(p) + (local ? ' (' + t('you') + ')' : ''));
            var role = roleOf(p);
            if (role !== 'student') {
                text.appendChild(el('small', 'meet-person-role', t(role)));
            }
            item.appendChild(avatar);
            item.appendChild(text);
            var states = el('span', 'meet-person-states');
            if (hands[p.identity]) {
                var hand = icon('hand-index-thumb-fill');
                hand.classList.add('text-warning');
                hand.setAttribute('aria-hidden', 'false');
                hand.setAttribute('role', 'img');
                hand.setAttribute('aria-label', t('hand_up'));
                states.appendChild(hand);
            }
            var mic = publication(p, Track.Source.Microphone);
            var micIcon = icon(mic ? 'mic-fill' : 'mic-mute-fill');
            micIcon.setAttribute('aria-hidden', 'false');
            micIcon.setAttribute('role', 'img');
            micIcon.setAttribute('aria-label', mic ? t('mic_on') : t('mic_off'));
            if (!mic) {
                micIcon.classList.add('text-danger');
            }
            states.appendChild(micIcon);
            var cam = publication(p, Track.Source.Camera);
            var camIcon = icon(cam ? 'camera-video-fill' : 'camera-video-off');
            camIcon.setAttribute('aria-hidden', 'false');
            camIcon.setAttribute('role', 'img');
            camIcon.setAttribute('aria-label', cam ? t('cam_on') : t('cam_off'));
            states.appendChild(camIcon);
            item.appendChild(states);

            if (isHost && !local && role !== 'host') {
                var tools = el('span', 'meet-person-tools');
                var add = function (iconName, label, handler, hidden) {
                    if (hidden) {
                        return;
                    }
                    var button = el('button', 'btn btn-sm btn-link meet-person-tool');
                    button.type = 'button';
                    button.title = label;
                    button.setAttribute('aria-label', label + ': ' + nameOf(p));
                    button.appendChild(icon(iconName));
                    button.addEventListener('click', handler);
                    tools.appendChild(button);
                };
                add('hand-thumbs-down', t('lower_hand'), function () {
                    send('ctl', { lower: true }, p.identity);
                    hands[p.identity] = false;
                    refresh();
                }, !hands[p.identity]);
                add('mic-mute', t('mute'), function () {
                    moderate('mute', p.identity).then(function () { send('ctl', { notice: 'muted' }, p.identity); });
                }, !mic);
                add('camera-video-off', t('stop_camera'), function () {
                    moderate('stop_camera', p.identity).then(function () { send('ctl', { notice: 'camera' }, p.identity); });
                }, !cam);
                var allowed = !!screenAllowed[p.identity];
                add(allowed ? 'display-fill' : 'display', allowed ? t('deny_screen') : t('allow_screen'), function () {
                    moderate(allowed ? 'deny_screen' : 'allow_screen', p.identity).then(function () {
                        screenAllowed[p.identity] = !allowed;
                        toast(allowed ? t('screen_denied') : t('screen_allowed'));
                        refresh();
                    });
                }, false);
                add('person-x', t('remove'), function () {
                    if (window.confirm(t('remove_confirm', { name: nameOf(p) }))) {
                        moderate('remove', p.identity);
                    }
                }, false);
                item.appendChild(tools);
            }
            list.appendChild(item);
        });
    }

    function renderControls() {
        var lp = room.localParticipant;
        var micOn = lp.isMicrophoneEnabled;
        var camOn = lp.isCameraEnabled;
        var screenOn = lp.isScreenShareEnabled;
        var mic = $('meet-mic');
        mic.setAttribute('aria-pressed', micOn ? 'true' : 'false');
        mic.querySelector('i').className = 'bi ' + (micOn ? 'bi-mic-fill' : 'bi-mic-mute-fill');
        var micAllowed = may('microphone');
        mic.disabled = !micAllowed && !micOn;
        mic.title = micAllowed ? (micOn ? t('mic_on') : t('mic_off')) : t('mic_locked_hint');
        mic.classList.toggle('is-off', !micOn);
        var cam = $('meet-cam');
        cam.setAttribute('aria-pressed', camOn ? 'true' : 'false');
        cam.querySelector('i').className = 'bi ' + (camOn ? 'bi-camera-video-fill' : 'bi-camera-video-off-fill');
        cam.classList.toggle('is-off', !camOn);
        cam.title = camOn ? t('cam_on') : t('cam_off');
        var share = $('meet-share');
        share.hidden = !isHost && !may('screen_share') && !screenOn;
        share.setAttribute('aria-pressed', screenOn ? 'true' : 'false');
        share.classList.toggle('is-active', screenOn);
        share.title = screenOn ? t('screen_on') : t('screen_off');
        var hand = $('meet-hand');
        var up = !!hands[myIdentity];
        hand.setAttribute('aria-pressed', up ? 'true' : 'false');
        hand.classList.toggle('is-active', up);
        hand.title = up ? t('hand_down') : t('hand_up');
        $('meet-lock-chip').hidden = !micsLocked;
        var lock = $('meet-lock');
        if (lock) {
            lock.setAttribute('aria-pressed', micsLocked ? 'true' : 'false');
            lock.querySelector('span').textContent = micsLocked ? t('unlock_mics') : t('lock_mics');
            lock.querySelector('i').className = 'bi ' + (micsLocked ? 'bi-unlock' : 'bi-lock');
        }
        $('meet-count').textContent = String(room.remoteParticipants.size + 1);
    }

    function refresh() {
        if (!room) {
            return;
        }
        var present = {};
        everyone().forEach(function (p) {
            present[p.identity] = true;
            renderTile(p);
        });
        document.querySelectorAll('#meet-grid .meet-tile').forEach(function (tile) {
            if (!present[tile.dataset.identity]) {
                tile.remove();
            }
        });
        Object.keys(hands).forEach(function (identity) {
            if (!present[identity]) {
                delete hands[identity];
            }
        });
        // Order the tiles like the list: the teacher first.
        var grid = $('meet-grid');
        everyone().forEach(function (p) {
            grid.appendChild(tileFor(p));
        });
        grid.dataset.count = String(Math.min(everyone().length, 13));
        renderSpotlight();
        renderPeople();
        renderControls();
    }

    // ---- Audio ------------------------------------------------------------------

    function attachAudio(track, pub) {
        if (track.kind !== Track.Kind.Audio) {
            return;
        }
        var element = track.attach();
        element.dataset.trackSid = pub.trackSid;
        $('meet-audio-sink').appendChild(element);
    }

    function detachAudio(track) {
        if (track.kind === Track.Kind.Audio) {
            track.detach().forEach(function (element) { element.remove(); });
        }
    }

    // ---- Chat -------------------------------------------------------------------

    function addMessage(participant, text) {
        var list = $('meet-chat-list');
        var item = el('li', 'meet-message' + (participant === room.localParticipant ? ' is-mine' : '') + (roleOf(participant) === 'host' ? ' is-host' : ''));
        var head = el('div', 'meet-message-head');
        head.appendChild(el('strong', null, nameOf(participant)));
        var time = el('time', null, new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        time.dir = 'ltr';
        head.appendChild(time);
        item.appendChild(head);
        item.appendChild(el('div', 'meet-message-text', text));
        list.appendChild(item);
        list.scrollTop = list.scrollHeight;
        if ($('meet-chat').hidden || $('meet-panel').hidden) {
            if (participant !== room.localParticipant) {
                unread++;
                $('meet-unread').textContent = String(unread);
                $('meet-unread').hidden = false;
                if (roleOf(participant) === 'host') {
                    toast(t('chat_from_host', { text: text.slice(0, 80) }));
                }
            }
        }
    }

    // ---- Panel ------------------------------------------------------------------

    function openPanel(which) {
        var panel = $('meet-panel');
        var alreadyOpen = !panel.hidden && $('meet-' + which).hidden === false;
        if (alreadyOpen) {
            panel.hidden = true;
        } else {
            panel.hidden = false;
            ['people', 'chat'].forEach(function (name) {
                $('meet-' + name).hidden = name !== which;
                $('meet-tab-' + name).setAttribute('aria-selected', name === which ? 'true' : 'false');
            });
            if (which === 'chat') {
                unread = 0;
                $('meet-unread').hidden = true;
                $('meet-chat-input').focus();
            }
        }
        $('meet-open-people').setAttribute('aria-pressed', !panel.hidden && which === 'people' ? 'true' : 'false');
        $('meet-open-chat').setAttribute('aria-pressed', !panel.hidden && which === 'chat' ? 'true' : 'false');
        $('meet-main').classList.toggle('has-panel', !panel.hidden);
    }

    // ---- Ending -----------------------------------------------------------------

    function showEnded(text, canRejoin) {
        $('meet-main').hidden = true;
        $('meet-controls').hidden = true;
        $('meet-lobby').hidden = true;
        $('meet-ended').hidden = false;
        $('meet-ended-text').textContent = text;
        $('meet-rejoin').hidden = !canRejoin;
        $('meet-audio-sink').textContent = '';
    }

    // ---- Joining ----------------------------------------------------------------

    function setStatus(text, isError) {
        var status = $('meet-status');
        status.textContent = text;
        status.classList.toggle('text-danger', !!isError);
    }

    function join() {
        var button = $('meet-join');
        button.disabled = true;
        setStatus(t('connecting'));
        var body = new URLSearchParams();
        body.set('_csrf', root.dataset.csrf);
        fetch(root.dataset.tokenUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok || !data.token) {
                        throw new Error(data.error || t('connect_error'));
                    }
                    return data;
                });
            })
            .then(function (data) {
                micsLocked = !!data.micsLocked;
                room = new LK.Room({ adaptiveStream: true, dynacast: true });
                wire(room);
                return room.connect(data.url, data.token).then(function () { return data; });
            })
            .then(function () {
                $('meet-lobby').hidden = true;
                $('meet-ended').hidden = true;
                $('meet-main').hidden = false;
                $('meet-controls').hidden = false;
                refresh();
                var wantMic = $('meet-start-mic').checked && may('microphone');
                var wantCam = $('meet-start-cam').checked;
                if (wantMic) {
                    room.localParticipant.setMicrophoneEnabled(true).catch(function () { toast(t('device_error'), 'danger'); }).then(refresh);
                }
                if (wantCam) {
                    room.localParticipant.setCameraEnabled(true).catch(function () { toast(t('device_error'), 'danger'); }).then(refresh);
                }
                if (!room.canPlaybackAudio) {
                    $('meet-audio').hidden = false;
                }
            })
            .catch(function (error) {
                button.disabled = false;
                setStatus(error && error.message ? error.message : t('connect_error'), true);
                if (room) {
                    room.disconnect();
                    room = null;
                }
            });
    }

    function wire(r) {
        r.on(RoomEvent.ParticipantConnected, function (p) {
            toast(t('joined', { name: nameOf(p) }));
            // A raised hand is told again to whoever arrives later.
            if (hands[myIdentity]) {
                send('hand', { raised: true }, p.identity);
            }
            if (isHost && micsLocked) {
                send('ctl', { micsLocked: true }, p.identity);
            }
            refresh();
        });
        r.on(RoomEvent.ParticipantDisconnected, function (p) {
            toast(t('left', { name: nameOf(p) }));
            delete hands[p.identity];
            refresh();
        });
        r.on(RoomEvent.TrackSubscribed, function (track, pub) {
            attachAudio(track, pub);
            refresh();
        });
        r.on(RoomEvent.TrackUnsubscribed, function (track) {
            detachAudio(track);
            refresh();
        });
        [RoomEvent.TrackMuted, RoomEvent.TrackUnmuted, RoomEvent.LocalTrackPublished, RoomEvent.LocalTrackUnpublished,
            RoomEvent.TrackPublished, RoomEvent.TrackUnpublished, RoomEvent.ActiveSpeakersChanged].forEach(function (name) {
            r.on(name, refresh);
        });
        var allowedBefore = null;
        r.on(RoomEvent.ParticipantPermissionsChanged, function (previous, participant) {
            if (participant === r.localParticipant) {
                var now = { mic: may('microphone'), screen: may('screen_share') };
                if (!isHost && allowedBefore) {
                    if (now.screen !== allowedBefore.screen) {
                        toast(now.screen ? t('screen_allowed') : t('screen_denied'));
                    }
                    if (now.mic !== allowedBefore.mic) {
                        toast(now.mic ? t('mics_unlocked') : t('mics_locked'));
                    }
                }
                allowedBefore = now;
                if (!now.screen && r.localParticipant.isScreenShareEnabled) {
                    r.localParticipant.setScreenShareEnabled(false).catch(function () {});
                }
                micsLocked = !isHost && !now.mic;
            }
            refresh();
        });
        r.on(RoomEvent.Connected, function () {
            allowedBefore = { mic: may('microphone'), screen: may('screen_share') };
        });
        r.on(RoomEvent.AudioPlaybackStatusChanged, function () {
            $('meet-audio').hidden = r.canPlaybackAudio;
        });
        r.on(RoomEvent.Reconnecting, function () { toast(t('reconnecting')); });
        r.on(RoomEvent.Reconnected, function () { toast(t('reconnected')); });
        r.on(RoomEvent.DataReceived, function (payload, participant, kind, topic) {
            if (!participant) {
                return;
            }
            var data;
            try {
                data = JSON.parse(decoder.decode(payload));
            } catch (error) {
                return;
            }
            var fromHost = roleOf(participant) === 'host';
            if (topic === 'chat' && typeof data.text === 'string' && data.text.trim() !== '') {
                addMessage(participant, data.text.slice(0, 500));
            } else if (topic === 'hand') {
                var was = !!hands[participant.identity];
                hands[participant.identity] = !!data.raised;
                if (data.raised && !was && (isHost || roleOf(participant) === 'host')) {
                    toast(t('hand_raised', { name: nameOf(participant) }));
                }
                refresh();
            } else if (topic === 'ctl' && fromHost) {
                if (data.lower) {
                    hands[myIdentity] = false;
                    send('hand', { raised: false });
                    toast(t('hand_lowered_by_host'));
                }
                if (data.notice === 'muted') {
                    toast(t('muted_by_host'));
                }
                if (data.notice === 'camera') {
                    toast(t('camera_stopped_by_host'));
                }
                if (data.notice === 'everyone_muted') {
                    toast(t('everyone_muted'));
                }
                if (typeof data.micsLocked === 'boolean') {
                    micsLocked = data.micsLocked;
                }
                refresh();
            }
        });
        r.on(RoomEvent.Disconnected, function (reason) {
            room = null;
            if (leaving) {
                return;
            }
            var R = LK.DisconnectReason || {};
            if (reason === R.ROOM_DELETED) {
                showEnded(t('ended_text'), false);
            } else if (reason === R.PARTICIPANT_REMOVED) {
                showEnded(t('removed_text'), false);
            } else {
                showEnded(t('disconnected_text'), true);
            }
        });
    }

    // ---- Buttons ----------------------------------------------------------------

    $('meet-join').addEventListener('click', join);
    $('meet-rejoin').addEventListener('click', function () {
        $('meet-ended').hidden = true;
        $('meet-lobby').hidden = false;
        $('meet-grid').textContent = '';
        $('meet-chat-list').textContent = '';
        join();
    });
    $('meet-audio').addEventListener('click', function () {
        if (room) {
            room.startAudio().then(function () { $('meet-audio').hidden = true; });
        }
    });
    $('meet-mic').addEventListener('click', function () {
        if (!room) {
            return;
        }
        var lp = room.localParticipant;
        lp.setMicrophoneEnabled(!lp.isMicrophoneEnabled).catch(function () { toast(t('device_error'), 'danger'); }).then(refresh);
    });
    $('meet-cam').addEventListener('click', function () {
        if (!room) {
            return;
        }
        var lp = room.localParticipant;
        lp.setCameraEnabled(!lp.isCameraEnabled).catch(function () { toast(t('device_error'), 'danger'); }).then(refresh);
    });
    $('meet-share').addEventListener('click', function () {
        if (!room) {
            return;
        }
        var lp = room.localParticipant;
        lp.setScreenShareEnabled(!lp.isScreenShareEnabled, { audio: true }).catch(function (error) {
            if (!error || error.name !== 'NotAllowedError') {
                toast(t('screen_error'), 'danger');
            }
        }).then(refresh);
    });
    $('meet-hand').addEventListener('click', function () {
        hands[myIdentity] = !hands[myIdentity];
        send('hand', { raised: hands[myIdentity] });
        refresh();
    });
    $('meet-open-people').addEventListener('click', function () { openPanel('people'); });
    $('meet-open-chat').addEventListener('click', function () { openPanel('chat'); });
    $('meet-tab-people').addEventListener('click', function () { openPanel('people'); });
    $('meet-tab-chat').addEventListener('click', function () { openPanel('chat'); });
    $('meet-panel-close').addEventListener('click', function () {
        $('meet-panel').hidden = true;
        $('meet-main').classList.remove('has-panel');
        $('meet-open-people').setAttribute('aria-pressed', 'false');
        $('meet-open-chat').setAttribute('aria-pressed', 'false');
    });
    $('meet-chat-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var input = $('meet-chat-input');
        var text = input.value.trim().slice(0, 500);
        if (!text || !room) {
            return;
        }
        send('chat', { text: text });
        addMessage(room.localParticipant, text);
        input.value = '';
    });
    $('meet-leave').addEventListener('click', function () {
        leaving = true;
        var back = root.dataset.back;
        if (room) {
            room.disconnect().finally(function () { window.location.href = back; });
        } else {
            window.location.href = back;
        }
    });
    document.querySelectorAll('[data-meet-moderate]').forEach(function (button) {
        button.addEventListener('click', function () {
            moderate(button.dataset.meetModerate).then(function () {
                send('ctl', { notice: 'everyone_muted' });
                toast(t('everyone_muted'));
            });
        });
    });
    var lockButton = $('meet-lock');
    if (lockButton) {
        lockButton.addEventListener('click', function () {
            moderate(micsLocked ? 'unlock_mics' : 'lock_mics').then(function (data) {
                micsLocked = !!data.micsLocked;
                send('ctl', { micsLocked: micsLocked });
                toast(micsLocked ? t('mics_locked') : t('mics_unlocked'));
                refresh();
            });
        });
    }
    var endForm = $('meet-end-form');
    if (endForm) {
        endForm.addEventListener('submit', function () { leaving = true; });
    }

    // Elapsed time since the teacher opened the meeting.
    var started = parseInt(root.dataset.started, 10) || Date.now();
    var tick = function () {
        var seconds = Math.max(0, Math.floor((Date.now() - started) / 1000));
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        $('meet-clock').textContent = (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    };
    tick();
    setInterval(tick, 1000);

    window.addEventListener('beforeunload', function () {
        if (room) {
            leaving = true;
            room.disconnect();
        }
    });
})();
