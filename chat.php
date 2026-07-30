<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';

$user = Auth::require();
$db   = getDB();

// Channels this user belongs to (public)
$stmt = $db->prepare("
    SELECT c.id, c.name, c.slug, c.type, cm.last_read_id,
           (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id=c.id AND m.id > cm.last_read_id AND m.is_deleted=0 AND m.user_id != ?) as unread,
           (SELECT MAX(id) FROM chat_messages WHERE channel_id=c.id AND is_deleted=0) as last_id
    FROM chat_channels c
    JOIN chat_members cm ON cm.channel_id=c.id AND cm.user_id=?
    WHERE c.is_active=1 AND c.type='public'
    ORDER BY c.name ASC
");
$stmt->execute([$user['id'], $user['id']]);
$publicChannels = $stmt->fetchAll();

// DM channels
$dmStmt = $db->prepare("
    SELECT c.id, c.name, c.slug, c.type, cm.last_read_id,
           (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id=c.id AND m.id > cm.last_read_id AND m.is_deleted=0 AND m.user_id != ?) as unread,
           (SELECT MAX(id) FROM chat_messages WHERE channel_id=c.id AND is_deleted=0) as last_id,
           (SELECT ocm.user_id FROM chat_members ocm WHERE ocm.channel_id=c.id AND ocm.user_id != ? LIMIT 1) as other_id,
           (SELECT u.name FROM chat_members ocm JOIN users u ON u.id=ocm.user_id WHERE ocm.channel_id=c.id AND ocm.user_id != ? LIMIT 1) as other_name,
           (SELECT u.avatar_color FROM chat_members ocm JOIN users u ON u.id=ocm.user_id WHERE ocm.channel_id=c.id AND ocm.user_id != ? LIMIT 1) as other_color
    FROM chat_channels c
    JOIN chat_members cm ON cm.channel_id=c.id AND cm.user_id=?
    WHERE c.is_active=1 AND c.type='direct'
    ORDER BY (SELECT MAX(id) FROM chat_messages WHERE channel_id=c.id AND is_deleted=0) DESC
");
$dmStmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$dmChannels = $dmStmt->fetchAll();

// All active staff for "New DM" picker (with online status)
$allStaff = $db->query("SELECT id, name, avatar_color, role FROM users WHERE is_active=1 ORDER BY name")->fetchAll();

// Online users (active in last 30 min)
$onlineIds = [];
$onlineStmt = $db->query("SELECT DISTINCT user_id FROM sessions WHERE expires_at > NOW() AND COALESCE(last_active,created_at) > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
foreach ($onlineStmt->fetchAll() as $r) $onlineIds[] = (int)$r['user_id'];

$canCreateChannel = in_array($user['role'], ['head_it','it_admin','md','bdm','hr','head_outsourcing','head_compliance','head_accounts','head_training','head_cso','cs_manager','training_manager']);

// Default to first public channel
$defaultChannel = $publicChannels[0]['id'] ?? ($dmChannels[0]['id'] ?? 0);

Layout::shell($user, 'chat', 0, 'HRI Chat');
?>
<style>
/* Chat layout fills .hri-main which is already flex column, height: calc(100vh - 64px) */
.chat-wrap{flex:1;display:flex;overflow:hidden;background:var(--g50);height:100%;}

/* Left sidebar */
.chat-sb{width:240px;flex-shrink:0;background:var(--w);border-right:1px solid var(--g200);display:flex;flex-direction:column;overflow:hidden;}
.chat-sb-hd{padding:14px 14px 8px;font-size:11px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;justify-content:space-between;}
.chat-sb-hd button{border:none;background:none;cursor:pointer;color:var(--g400);font-size:18px;line-height:1;padding:0 2px;}
.chat-sb-hd button:hover{color:var(--navy);}
.chat-ch-list{overflow-y:auto;flex:1;}
.chat-ch-item{display:flex;align-items:center;gap:8px;padding:7px 12px;cursor:pointer;border-radius:7px;margin:1px 6px;font-size:13px;color:var(--g700);transition:background .1s;position:relative;}
.chat-ch-item:hover{background:var(--g100);}
.chat-ch-item.active{background:#e8f0fb;color:var(--navy);font-weight:600;}
.chat-ch-icon{font-size:14px;flex-shrink:0;width:20px;text-align:center;}
.chat-ch-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.chat-ch-badge{background:var(--navy);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;flex-shrink:0;}
.chat-av{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;}

/* Right panel */
.chat-panel{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.chat-pnl-hd{padding:12px 18px;border-bottom:1px solid var(--g200);background:var(--w);display:flex;align-items:center;gap:10px;flex-shrink:0;}
.chat-pnl-title{font-size:15px;font-weight:700;color:var(--navy);}
.chat-pnl-desc{font-size:12px;color:var(--g400);margin-left:auto;}
.chat-msgs{flex:1;overflow-y:auto;padding:16px 18px;display:flex;flex-direction:column;gap:2px;}
.chat-msg{display:flex;gap:10px;padding:3px 0;}
.chat-msg.same-sender{padding-top:1px;}
.chat-msg.same-sender .chat-msg-av{visibility:hidden;}
.chat-msg-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0;margin-top:2px;}
.chat-msg-body{flex:1;}
.chat-msg-meta{font-size:11.5px;color:var(--g400);margin-bottom:2px;display:flex;align-items:baseline;gap:8px;}
.chat-msg-name{font-weight:700;color:var(--g700);font-size:13px;}
.chat-msg-time{font-size:11px;}
.chat-msg-text{font-size:13.5px;color:var(--g900);line-height:1.55;word-break:break-word;}
.chat-msg-text a{color:var(--navy);text-decoration:underline;}
.chat-reply-quote{background:var(--g100);border-left:3px solid var(--g300);border-radius:0 6px 6px 0;padding:4px 10px;margin-bottom:4px;font-size:12px;color:var(--g500);}
.chat-reply-quote strong{color:var(--g700);}
.chat-msg-row:hover .chat-msg-actions{opacity:1;}
.chat-msg-row{position:relative;}
.chat-msg-actions{position:absolute;right:0;top:-2px;opacity:0;background:var(--w);border:1px solid var(--g200);border-radius:8px;padding:2px 4px;display:flex;gap:2px;box-shadow:var(--shadow);}
.chat-msg-actions button{border:none;background:none;cursor:pointer;font-size:13px;padding:3px 5px;border-radius:5px;}
.chat-msg-actions button:hover{background:var(--g100);}

/* Input bar */
.chat-input-wrap{padding:12px 16px;border-top:1px solid var(--g200);background:var(--w);flex-shrink:0;}
.chat-reply-bar{background:var(--g100);border-radius:8px 8px 0 0;padding:6px 12px;font-size:12px;color:var(--g500);display:flex;align-items:center;gap:8px;margin-bottom:2px;}
.chat-reply-bar strong{color:var(--g700);}
.chat-reply-bar button{margin-left:auto;border:none;background:none;cursor:pointer;font-size:16px;color:var(--g400);}
.chat-input-row{display:flex;gap:8px;align-items:flex-end;}
.chat-input{flex:1;border:1.5px solid var(--g200);border-radius:10px;padding:9px 14px;font-size:13.5px;font-family:inherit;color:var(--g900);outline:none;background:var(--g50);resize:none;max-height:120px;min-height:40px;transition:border-color .15s;line-height:1.45;}
.chat-input:focus{border-color:var(--navy);background:var(--w);}
.chat-send-btn{width:38px;height:38px;border-radius:10px;background:var(--navy);color:#fff;border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s;}
.chat-send-btn:hover{background:#003a72;}
.chat-send-btn:disabled{background:var(--g200);cursor:default;}

/* Empty state */
.chat-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--g400);gap:8px;}
.chat-empty-icon{font-size:40px;}
.chat-no-channel{flex:1;display:flex;align-items:center;justify-content:center;color:var(--g400);font-size:14px;}

/* Modals */
.chat-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center;}
.chat-modal-bg.open{display:flex;}
.chat-modal{background:var(--w);border-radius:14px;padding:24px;width:380px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.chat-modal h3{margin:0 0 16px;font-size:15px;color:var(--navy);}
.cm-fg{margin-bottom:12px;}
.cm-fg label{display:block;font-size:10.5px;font-weight:700;color:var(--g500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.cm-fg input,.cm-fg textarea{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:inherit;outline:none;background:var(--g50);}
.cm-fg input:focus,.cm-fg textarea:focus{border-color:var(--navy);}
.cm-fg textarea{resize:none;height:60px;}
.cm-btns{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;}
.cm-btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;}
.cm-btn.primary{background:var(--navy);color:#fff;}
.cm-btn.secondary{background:var(--g100);color:var(--g700);}

/* Staff picker for DMs */
.staff-pick-list{max-height:260px;overflow-y:auto;border:1.5px solid var(--g200);border-radius:8px;margin-top:8px;}
.staff-pick-item{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;transition:background .1s;font-size:13px;}
.staff-pick-item:hover{background:var(--g50);}
.staff-pick-search{width:100%;border:1.5px solid var(--g200);border-radius:8px;padding:8px 11px;font-size:13px;font-family:inherit;outline:none;background:var(--g50);margin-bottom:4px;}
.staff-pick-search:focus{border-color:var(--navy);}

/* Day divider */
.chat-day{text-align:center;font-size:11.5px;color:var(--g400);margin:12px 0;position:relative;}
.chat-day::before,.chat-day::after{content:'';position:absolute;top:50%;width:40%;height:1px;background:var(--g200);}
.chat-day::before{left:0;}.chat-day::after{right:0;}

@media(max-width:640px){
    .chat-sb{width:200px;}
    .chat-msgs{padding:10px 12px;}
    .chat-input-wrap{padding:8px 10px;}
}
@media(max-width:480px){
    .chat-sb{display:none;}
    .chat-sb.mob-open{display:flex;position:fixed;top:64px;left:0;bottom:0;z-index:500;border-right:none;box-shadow:4px 0 20px rgba(0,0,0,.2);}
}

/* Online dot */
.chat-online-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;flex-shrink:0;border:2px solid #fff;display:inline-block;}
.chat-ch-item-wrap{position:relative;}
.chat-av-wrap-online{position:relative;flex-shrink:0;}
.chat-av-wrap-online .chat-online-dot{position:absolute;bottom:-1px;right:-1px;border:2px solid var(--w);}

/* Input extras */
.chat-icon-btn{border:none;background:none;cursor:pointer;font-size:18px;padding:4px 6px;border-radius:6px;color:var(--g500);flex-shrink:0;line-height:1;}
.chat-icon-btn:hover{background:var(--g100);color:var(--navy);}
.chat-file-prev{display:flex;align-items:center;gap:8px;background:var(--g50);border:1px solid var(--g200);border-bottom:none;border-radius:8px 8px 0 0;padding:6px 12px;font-size:12px;color:var(--g700);}
.chat-file-prev span.fn{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.chat-file-prev button{border:none;background:none;cursor:pointer;font-size:15px;color:var(--g400);padding:0;}

/* Emoji picker */
.chat-emoji-pick{background:var(--w);border:1px solid var(--g200);border-bottom:none;padding:6px 10px;}
.chat-emoji-tabs{display:flex;gap:2px;margin-bottom:4px;overflow-x:auto;scrollbar-width:none;}
.chat-emoji-tab{border:none;background:none;cursor:pointer;font-size:16px;padding:3px 6px;border-radius:5px;line-height:1;}
.chat-emoji-tab:hover,.chat-emoji-tab.active{background:var(--g100);}
.chat-emoji-grid{display:grid;grid-template-columns:repeat(11,1fr);max-height:120px;overflow-y:auto;}
.chat-emoji-btn{border:none;background:none;cursor:pointer;font-size:18px;padding:3px;border-radius:4px;line-height:1;}
.chat-emoji-btn:hover{background:var(--g100);}

/* Attachment in messages */
.chat-att-img{max-width:280px;max-height:200px;border-radius:8px;margin-top:4px;cursor:pointer;display:block;}
.chat-att-file{display:inline-flex;align-items:center;gap:6px;background:var(--g100);border:1px solid var(--g200);border-radius:8px;padding:5px 12px;font-size:12px;color:var(--navy);text-decoration:none;margin-top:4px;}
.chat-att-file:hover{background:var(--g200);}
/* mobile-all-pages */
@media(max-width:768px){
    [class*='-grid'],[class*='layout']{grid-template-columns:1fr;}
}
</style>

<div class="chat-wrap">
    <!-- Left sidebar: channels + DMs -->
    <div class="chat-sb" id="chatSb">
        <div style="padding:10px 10px 4px;">
            <div class="chat-sb-hd" style="padding:6px 4px 4px;">
                <span>Channels</span>
                <?php if ($canCreateChannel): ?>
                <button onclick="openNewChannel()" title="New channel">&#43;</button>
                <?php endif; ?>
            </div>
            <?php foreach ($publicChannels as $ch): ?>
            <div class="chat-ch-item <?= $ch['id']==$defaultChannel?'active':'' ?>"
                 id="ch-<?= $ch['id'] ?>" onclick="loadChannel(<?= $ch['id'] ?>, '#<?= htmlspecialchars($ch['name']) ?>', '<?= $ch['type'] ?>')">
                <span class="chat-ch-icon">#</span>
                <span class="chat-ch-name"><?= htmlspecialchars($ch['name']) ?></span>
                <?php if ($ch['unread'] > 0): ?>
                <span class="chat-ch-badge" id="badge-<?= $ch['id'] ?>"><?= min($ch['unread'],99) ?></span>
                <?php else: ?>
                <span class="chat-ch-badge" id="badge-<?= $ch['id'] ?>" style="display:none;"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="padding:4px 10px;">
            <div class="chat-sb-hd" style="padding:6px 4px 4px;">
                <span>Direct Messages</span>
                <button onclick="openNewDM()" title="New DM">&#43;</button>
            </div>
            <?php foreach ($dmChannels as $ch):
                $dmOnline = in_array((int)($ch['other_id']??0), $onlineIds);
            ?>
            <div class="chat-ch-item <?= $ch['id']==$defaultChannel&&!$publicChannels?'active':'' ?>"
                 id="ch-<?= $ch['id'] ?>" onclick="loadChannel(<?= $ch['id'] ?>, <?= htmlspecialchars(json_encode($ch['other_name'] ?? 'DM'), ENT_QUOTES) ?>, 'direct')">
                <div class="chat-av-wrap-online">
                    <div class="chat-av" style="background:<?= htmlspecialchars($ch['other_color'] ?? '#64748b') ?>;width:22px;height:22px;font-size:9px;">
                        <?= htmlspecialchars(mb_substr($ch['other_name'] ?? '?', 0, 1)) ?>
                    </div>
                    <?php if ($dmOnline): ?><span class="chat-online-dot"></span><?php endif; ?>
                </div>
                <span class="chat-ch-name"><?= htmlspecialchars($ch['other_name'] ?? 'DM') ?></span>
                <?php if ($ch['unread'] > 0): ?>
                <span class="chat-ch-badge" id="badge-<?= $ch['id'] ?>"><?= min($ch['unread'],99) ?></span>
                <?php else: ?>
                <span class="chat-ch-badge" id="badge-<?= $ch['id'] ?>" style="display:none;"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right: message panel -->
    <div class="chat-panel">
        <div class="chat-pnl-hd" id="chatPnlHd">
            <button onclick="document.getElementById('chatSb').classList.toggle('mob-open')"
                    style="display:none;border:none;background:none;font-size:20px;cursor:pointer;color:var(--g400);"
                    class="chat-mob-menu" id="chatMobMenu">&#9776;</button>
            <span class="chat-pnl-title" id="chatPnlTitle">Select a channel</span>
            <span class="chat-pnl-desc" id="chatPnlDesc"></span>
        </div>
        <div class="chat-msgs" id="chatMsgs">
            <div class="chat-no-channel">&#128172; Pick a channel or DM to start</div>
        </div>
        <div class="chat-input-wrap" id="chatInputWrap" style="display:none;">
            <div id="chatReplyBar" class="chat-reply-bar" style="display:none;">
                Replying to <strong id="chatReplyName"></strong>: <span id="chatReplyText"></span>
                <button onclick="cancelReply()">&#10005;</button>
            </div>
            <div id="chatFilePrev" class="chat-file-prev" style="display:none;">
                <span>&#128206;</span>
                <span class="fn" id="chatFileName"></span>
                <button onclick="clearChatFile()">&#10005;</button>
            </div>
            <div id="chatEmojiPick" class="chat-emoji-pick" style="display:none;">
                <div id="chatEmojiTabs" class="chat-emoji-tabs"></div>
                <div id="chatEmojiGrid" class="chat-emoji-grid"></div>
            </div>
            <div class="chat-input-row">
                <button class="chat-icon-btn" onclick="toggleChatEmoji()" title="Emoji">&#128512;</button>
                <button class="chat-icon-btn" onclick="document.getElementById('chatFileInp').click()" title="Attach file">&#128206;</button>
                <input type="file" id="chatFileInp" style="display:none;" onchange="chatFileSelected(this)"/>
                <textarea class="chat-input" id="chatInput" placeholder="Message..." rows="1"
                          onkeydown="handleInputKey(event)" oninput="autoResize(this)"></textarea>
                <button class="chat-send-btn" id="chatSendBtn" onclick="sendMessage()">&#9658;</button>
            </div>
        </div>
    </div>
</div>

<!-- New Channel Modal -->
<div class="chat-modal-bg" id="newChannelModal">
    <div class="chat-modal">
        <h3>&#128228; Create New Channel</h3>
        <div class="cm-fg"><label>Channel Name *</label><input type="text" id="newChName" placeholder="e.g. Finance Team"/></div>
        <div class="cm-fg"><label>Description</label><textarea id="newChDesc" placeholder="What's this channel for?"></textarea></div>
        <div class="cm-btns">
            <button class="cm-btn secondary" onclick="closeModal('newChannelModal')">Cancel</button>
            <button class="cm-btn primary" onclick="createChannel()">Create Channel</button>
        </div>
    </div>
</div>

<!-- New DM Modal -->
<div class="chat-modal-bg" id="newDMModal">
    <div class="chat-modal">
        <h3>&#128172; Start Direct Message</h3>
        <input type="text" class="staff-pick-search" placeholder="Search staff..." id="dmSearch" oninput="filterStaff()"/>
        <div class="staff-pick-list" id="staffPickList">
            <?php foreach ($allStaff as $s):
                if ($s['id'] === $user['id']) continue;
                $initials  = mb_substr($s['name'], 0, 1);
                $color     = $s['avatar_color'] ?? '#64748b';
                $roleLabel = ROLES[$s['role']]['label'] ?? $s['role'];
                $isOn      = in_array((int)$s['id'], $onlineIds);
            ?>
            <div class="staff-pick-item" data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>"
                 onclick="startDM(<?= $s['id'] ?>)">
                <div class="chat-av-wrap-online" style="flex-shrink:0;">
                    <div class="chat-av" style="background:<?= htmlspecialchars($color) ?>;"><?= htmlspecialchars($initials) ?></div>
                    <?php if ($isOn): ?><span class="chat-online-dot"></span><?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:600;color:var(--g900);"><?= htmlspecialchars($s['name']) ?></div>
                    <div style="font-size:11.5px;color:var(--g400);"><?= htmlspecialchars($roleLabel) ?>
                        <?php if ($isOn): ?><span style="color:#22c55e;font-weight:600;"> · Online</span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cm-btns"><button class="cm-btn secondary" onclick="closeModal('newDMModal')">Cancel</button></div>
    </div>
</div>

<script>
const CHAT = {
    channelId: null,
    channelName: '',
    lastId: 0,
    pollTimer: null,
    replyTo: null,
    userId: <?= $user['id'] ?>,
    userName: <?= json_encode($user['name']) ?>,

    init: function() {
        const defaultId = <?= (int)$defaultChannel ?>;
        if (defaultId) {
            const el = document.getElementById('ch-' + defaultId);
            if (el) el.click();
        }
        this.startPolling();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && this.channelId) {
                this.poll();
            }
        });
        // Show mobile menu button
        if (window.innerWidth <= 480) {
            document.getElementById('chatMobMenu').style.display = 'block';
        }
    },

    startPolling: function() {
        clearInterval(this.pollTimer);
        this.pollTimer = setInterval(() => {
            if (document.visibilityState !== 'visible') return;
            if (!this.channelId) return;
            this.poll();
        }, 15000);
    },

    loadChannel: function(id, name, type) {
        this.channelId = id;
        this.channelName = name;
        this.lastId = 0;
        this.replyTo = null;

        // Update sidebar active state
        document.querySelectorAll('.chat-ch-item').forEach(el => el.classList.remove('active'));
        const item = document.getElementById('ch-' + id);
        if (item) item.classList.add('active');

        // Update header
        const icon = type === 'direct' ? '&#128172;' : '#';
        document.getElementById('chatPnlTitle').innerHTML = icon + ' ' + this.escHtml(name);
        document.getElementById('chatInputWrap').style.display = '';
        document.getElementById('chatMsgs').innerHTML = '<div style="text-align:center;padding:20px;color:var(--g400);font-size:13px;">Loading...</div>';
        document.getElementById('chatReplyBar').style.display = 'none';

        // Read badge count before clearing — needed to correct total_unread below
        const badge = document.getElementById('badge-' + id);
        const prevChannelUnread = badge ? (parseInt(badge.textContent) || 0) : 0;
        if (badge) { badge.style.display = 'none'; badge.textContent = ''; }

        // Load last 60 messages
        fetch('api/chat/poll.php?channel_id=' + id + '&after=0', {
            credentials: 'same-origin',
            headers: {'X-CSRF-Token': window.CSRF_TOKEN}
        })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            document.getElementById('chatMsgs').innerHTML = '';
            if (data.messages.length === 0) {
                document.getElementById('chatMsgs').innerHTML = '<div class="chat-empty"><div class="chat-empty-icon">&#128172;</div><div>No messages yet. Say hello!</div></div>';
            } else {
                this.renderMessages(data.messages, false);
                this.lastId = data.messages[data.messages.length - 1].id;
                this.scrollToBottom();
                this.markRead();
            }
            // total_unread from server still includes this channel (markRead is async),
            // so subtract the count we had for it to show an immediately correct badge.
            this.updateNavBadge(Math.max(0, data.total_unread - prevChannelUnread));
        });
    },

    poll: function() {
        if (!this.channelId) return;
        fetch('api/chat/poll.php?channel_id=' + this.channelId + '&after=' + this.lastId, {
            credentials: 'same-origin',
            headers: {'X-CSRF-Token': window.CSRF_TOKEN}
        })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            if (data.messages && data.messages.length > 0) {
                const wasAtBottom = this.isAtBottom();
                this.renderMessages(data.messages, true);
                this.lastId = data.messages[data.messages.length - 1].id;
                this.markRead();
                if (wasAtBottom) this.scrollToBottom();
            }
            this.updateNavBadge(data.total_unread);
        })
        .catch(() => {});
    },

    renderMessages: function(msgs, append) {
        const container = document.getElementById('chatMsgs');
        const frag = document.createDocumentFragment();
        let prevUserId = append ? this._lastRenderedUserId : null;
        let prevDate = append ? this._lastRenderedDate : null;

        msgs.forEach(msg => {
            const msgDate = msg.created_at.substring(0, 10);
            if (msgDate !== prevDate) {
                const div = document.createElement('div');
                div.className = 'chat-day';
                div.textContent = this.formatDate(msgDate);
                frag.appendChild(div);
                prevDate = msgDate;
                prevUserId = null;
            }

            const sameSender = (msg.user_id == prevUserId);
            const row = document.createElement('div');
            row.className = 'chat-msg-row';
            row.dataset.id = msg.id;

            const color = msg.avatar_color || '#64748b';
            const initials = msg.user_name ? msg.user_name.charAt(0).toUpperCase() : '?';
            const timeStr = msg.created_at.substring(11, 16);
            const isOwn = (msg.user_id == this.userId);

            let replyHtml = '';
            if (msg.reply_to_id && msg.reply_body) {
                replyHtml = `<div class="chat-reply-quote"><strong>${this.escHtml(msg.reply_user_name || 'Someone')}</strong>: ${this.escHtml(msg.reply_body.substring(0, 80))}${msg.reply_body.length > 80 ? '…' : ''}</div>`;
            }

            let attHtml = '';
            if (msg.attachment) {
                try {
                    const att = typeof msg.attachment === 'string' ? JSON.parse(msg.attachment) : msg.attachment;
                    if (att && att.url) {
                        if (att.type && att.type.startsWith('image/')) {
                            attHtml = `<img class="chat-att-img" src="${this.escHtml(att.url)}" alt="${this.escHtml(att.name)}" onclick="window.open(this.src)"/>`;
                        } else {
                            const kb = att.size ? ` (${Math.round(att.size/1024)}KB)` : '';
                            attHtml = `<a class="chat-att-file" href="${this.escHtml(att.url)}" target="_blank">&#128206; ${this.escHtml(att.name)}${kb}</a>`;
                        }
                    }
                } catch(e) {}
            }

            row.innerHTML = `
                <div class="chat-msg ${sameSender ? 'same-sender' : ''}">
                    <div class="chat-msg-av" style="background:${color};">${sameSender ? '' : initials}</div>
                    <div class="chat-msg-body">
                        ${!sameSender ? `<div class="chat-msg-meta"><span class="chat-msg-name">${this.escHtml(msg.user_name)}</span><span class="chat-msg-time">${timeStr}</span></div>` : ''}
                        ${replyHtml}
                        <div class="chat-msg-text">${this.linkify(this.escHtml(msg.body))}${attHtml ? '<br>'+attHtml : ''}</div>
                    </div>
                </div>
                <div class="chat-msg-actions">
                    <button onclick="setReply(${msg.id}, ${JSON.stringify(msg.user_name)}, ${JSON.stringify(msg.body)})" title="Reply">&#8617;</button>
                </div>`;
            frag.appendChild(row);
            prevUserId = msg.user_id;
        });

        this._lastRenderedUserId = prevUserId;
        this._lastRenderedDate = prevDate;
        container.appendChild(frag);
    },

    markRead: function() {
        if (!this.channelId || !this.lastId) return;
        const fd = new FormData();
        fd.append('channel_id', this.channelId);
        fd.append('last_id', this.lastId);
        fetch('api/chat/read.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'X-CSRF-Token': window.CSRF_TOKEN}, body: fd
        }).catch(() => {});
    },

    send: function(body, attachmentJson) {
        const fd = new FormData();
        fd.append('channel_id', this.channelId);
        fd.append('body', body);
        if (this.replyTo) fd.append('reply_to_id', this.replyTo.id);
        if (attachmentJson) fd.append('attachment', attachmentJson);

        const btn = document.getElementById('chatSendBtn');
        btn.disabled = true;
        cancelReply();

        fetch('api/chat/send.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'X-CSRF-Token': window.CSRF_TOKEN}, body: fd
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.ok && data.message) {
                const container = document.getElementById('chatMsgs');
                const noMsg = container.querySelector('.chat-empty,.chat-no-channel');
                if (noMsg) noMsg.remove();
                this.renderMessages([data.message], true);
                this.lastId = data.message.id;
                this.scrollToBottom();
            }
        })
        .catch(() => { btn.disabled = false; });
    },

    updateNavBadge: function(count) {
        ['chatNavBadge','chatTopBadge','chatBubbleBadge'].forEach(id => {
            const b = document.getElementById(id);
            if (!b) return;
            if (count > 0) { b.textContent = count > 99 ? '99+' : count; b.style.display = ''; }
            else { b.textContent = ''; b.style.display = 'none'; }
        });
        if (typeof window._hriChatUnread !== 'undefined') {
            window._hriChatUnread = count;
            if (typeof _hriUpdateTitle === 'function') _hriUpdateTitle();
        }
    },

    isAtBottom: function() {
        const el = document.getElementById('chatMsgs');
        return el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    },
    scrollToBottom: function() {
        const el = document.getElementById('chatMsgs');
        el.scrollTop = el.scrollHeight;
    },
    escHtml: function(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },
    linkify: function(s) {
        return s.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    },
    formatDate: function(d) {
        const today = new Date().toISOString().substring(0, 10);
        const yest  = new Date(Date.now()-86400000).toISOString().substring(0, 10);
        if (d === today) return 'Today';
        if (d === yest)  return 'Yesterday';
        return d;
    }
};

function loadChannel(id, name, type) { CHAT.loadChannel(id, name, type); }

var chatPendingFile = null;

function sendMessage() {
    const input = document.getElementById('chatInput');
    const body  = input.value.trim();
    if (!body && !chatPendingFile) return;
    if (!CHAT.channelId) return;
    input.value = ''; autoResize(input);

    if (chatPendingFile) {
        const btn = document.getElementById('chatSendBtn');
        btn.disabled = true;
        const ufd = new FormData(); ufd.append('file', chatPendingFile);
        fetch('api/chat/upload.php', {
            method:'POST', credentials:'same-origin',
            headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: ufd
        })
        .then(r => r.json())
        .then(u => {
            clearChatFile();
            btn.disabled = false;
            if (u.ok) CHAT.send(body || u.name, JSON.stringify({name:u.name,url:u.url,size:u.size,type:u.type}));
            else alert(u.error || 'Upload failed');
        })
        .catch(() => { btn.disabled = false; });
    } else {
        CHAT.send(body, null);
    }
}

/* ── Emoji picker ── */
var chatEmojiInit = false;
var CHAT_EMOJIS = [
    {t:'&#128512;', e:['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','🙃','😉','😍','🥰','😘','😋','😛','😜','🤪','😎','🤩','🥳','😏','😒','😔','😢','😭','😤','😠','😱','😨','🤯','😳','🤗','🤔','😶','🙄','😴','🤢','😷','🤕']},
    {t:'&#128075;', e:['👋','🤚','✋','🖖','👌','✌️','🤞','🤙','👈','👉','👆','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','🤝','🙏','💪','✍️']},
    {t:'&#128100;', e:['🧑','👨','👩','🧒','👦','👧','👴','👵','🧔','👱','💆','💇','🚶','🏃','💃','🕺','🧘','💼','👔','🎓','👑','🏆','🥇']},
    {t:'&#127807;', e:['🌟','⭐','🔥','💧','🌊','🌈','❄️','⚡','🌙','☀️','⛅','🌸','🌺','🌻','🌹','🍀','🌿','🌱','🌴','🐶','🐱','🦊','🐻','🐼','🦁','🐸','🐵','🦋','🐝']},
    {t:'&#127829;', e:['🍎','🍊','🍋','🍇','🍓','🍑','🥑','🍕','🍔','🍟','🌮','🍜','🍱','🍣','🍩','🍪','🎂','🍰','☕','🍺','🥂','🍷']},
    {t:'&#10084;&#65039;', e:['❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','❣️','💕','💯','✅','❌','⚠️','🔔','💬','💭','🎉','🎊','🏆','🎯','💡','🔑','📧','📅','🆗']},
];

function toggleChatEmoji() {
    const pick = document.getElementById('chatEmojiPick');
    if (!pick) return;
    if (pick.style.display === 'none') {
        if (!chatEmojiInit) {
            CHAT_EMOJIS.forEach((cat, i) => {
                const btn = document.createElement('button');
                btn.className = 'chat-emoji-tab' + (i===0?' active':'');
                btn.innerHTML = cat.t;
                btn.onclick = () => showChatEmojiCat(i);
                document.getElementById('chatEmojiTabs').appendChild(btn);
            });
            showChatEmojiCat(0);
            chatEmojiInit = true;
        }
        pick.style.display = 'block';
    } else {
        pick.style.display = 'none';
    }
}

function showChatEmojiCat(idx) {
    document.querySelectorAll('.chat-emoji-tab').forEach((b,i) => b.classList.toggle('active', i===idx));
    const grid = document.getElementById('chatEmojiGrid');
    grid.innerHTML = '';
    CHAT_EMOJIS[idx].e.forEach(emoji => {
        const btn = document.createElement('button');
        btn.className = 'chat-emoji-btn'; btn.textContent = emoji;
        btn.onclick = () => {
            const inp = document.getElementById('chatInput');
            const s = inp.selectionStart, e = inp.selectionEnd;
            inp.value = inp.value.substring(0,s) + emoji + inp.value.substring(e);
            inp.selectionStart = inp.selectionEnd = s + emoji.length;
            inp.focus();
        };
        grid.appendChild(btn);
    });
}

function chatFileSelected(inp) {
    if (!inp.files || !inp.files[0]) return;
    chatPendingFile = inp.files[0];
    document.getElementById('chatFilePrev').style.display = 'flex';
    document.getElementById('chatFileName').textContent = chatPendingFile.name + ' (' + Math.round(chatPendingFile.size/1024) + 'KB)';
}

function clearChatFile() {
    chatPendingFile = null;
    document.getElementById('chatFilePrev').style.display = 'none';
    const inp = document.getElementById('chatFileInp'); if(inp) inp.value='';
}

function handleInputKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function setReply(id, name, body) {
    CHAT.replyTo = {id, name, body};
    document.getElementById('chatReplyBar').style.display = 'flex';
    document.getElementById('chatReplyName').textContent = name;
    document.getElementById('chatReplyText').textContent = body.substring(0, 60) + (body.length > 60 ? '…' : '');
    document.getElementById('chatInput').focus();
}

function cancelReply() {
    CHAT.replyTo = null;
    document.getElementById('chatReplyBar').style.display = 'none';
}

function openNewChannel() { document.getElementById('newChannelModal').classList.add('open'); }
function openNewDM()      { document.getElementById('newDMModal').classList.add('open'); }
function closeModal(id)   { document.getElementById(id).classList.remove('open'); }

function createChannel() {
    const name = document.getElementById('newChName').value.trim();
    const desc = document.getElementById('newChDesc').value.trim();
    if (!name) return;
    const fd = new FormData();
    fd.append('name', name); fd.append('description', desc);
    fetch('api/chat/channels.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) { closeModal('newChannelModal'); location.reload(); }
        else alert(data.error || 'Failed to create channel');
    });
}

function startDM(userId) {
    closeModal('newDMModal');
    const fd = new FormData();
    fd.append('user_id', userId);
    fetch('api/chat/dm.php', {
        method:'POST', credentials:'same-origin',
        headers:{'X-CSRF-Token': window.CSRF_TOKEN}, body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (data.created) { location.reload(); }
            else { loadChannel(data.channel_id, '', 'direct'); }
        }
    });
}

function filterStaff() {
    const q = document.getElementById('dmSearch').value.toLowerCase();
    document.querySelectorAll('.staff-pick-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', () => { CHAT.init(); });
</script>
<?php Layout::end(); ?>
