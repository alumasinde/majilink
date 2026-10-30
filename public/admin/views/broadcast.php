<h1>Broadcast</h1>
<p class="lead">Send a push announcement to every customer or vendor. It is stored in-app and pushed to active devices.</p>

<div class="card">
    <form method="post" action="<?= e(admin_url('broadcast')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="broadcast_send">
        <input type="hidden" name="return" value="broadcast">
        <div class="field">
            <label for="audience">Audience</label>
            <select id="audience" name="audience" required>
                <option value="customers">All customers</option>
                <option value="vendors">All vendors</option>
                <option value="all">Everyone</option>
            </select>
        </div>
        <div class="field">
            <label for="title">Title</label>
            <input id="title" type="text" name="title" required maxlength="120">
        </div>
        <div class="field">
            <label for="body">Message</label>
            <textarea id="body" name="body" required maxlength="500"></textarea>
            <div class="hint">Keep it under 500 characters; it appears as a push notification.</div>
        </div>
        <button class="btn" type="submit">Send broadcast</button>
    </form>
</div>
