<?php declare(strict_types=1); ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b1020">
<meta name="description" content="DayPilot — your personal work memory, daily planner and AI assistant.">
<link rel="manifest" href="manifest.webmanifest?v=2.4.0">
<link rel="icon" href="assets/icon.svg">
<title>DayPilot — Work Memory & AI Assistant</title>
<link rel="stylesheet" href="assets/styles.css?v=2.4.0">
<link rel="stylesheet" href="assets/control-center.css?v=2.4.0">
</head>
<body>
<div id="app">
  <div id="authView" class="auth-screen">
    <section class="auth-card">
      <div class="brand brand-large"><img src="assets/icon.svg" alt="" class="brand-icon"><span>DayPilot</span></div>
      <div class="auth-kicker">WORK MEMORY · DAILY INTELLIGENCE</div>
      <h1>Remember what you worked on. Know what to do next.</h1>
      <p class="tagline">Capture the messy parts of your day. DayPilot turns them into tasks, work history, revision material and AI-ready memory.</p>
      <div id="authError" class="alert error hidden"></div>
      <form id="loginForm" class="stack">
        <label>Email<input id="loginEmail" type="email" autocomplete="email" required placeholder="you@example.com"></label>
        <label>Password<input id="loginPassword" type="password" autocomplete="current-password" required minlength="8" placeholder="••••••••"></label>
        <button class="primary wide" type="submit">Log in</button>
      </form>
      <button id="showRegister" class="link-button">Create an account</button>
      <form id="registerForm" class="stack hidden">
        <label>Name<input id="regName" autocomplete="name" required placeholder="Your name"></label>
        <label>Email<input id="regEmail" type="email" autocomplete="email" required placeholder="you@example.com"></label>
        <label>Password<input id="regPassword" type="password" autocomplete="new-password" required minlength="8" placeholder="At least 8 characters"></label>
        <button class="primary wide" type="submit">Create account</button>
      </form>
      <button id="showLogin" class="link-button hidden">Back to login</button>
    </section>
    <div class="auth-points">
      <span>✓ Daily brain dump</span><span>✓ Work memory + RAG</span><span>✓ AI planning</span><span>✓ Offline capture</span>
    </div>
  </div>

  <div id="appView" class="app-shell hidden">
    <aside class="sidebar">
      <div class="brand side-brand"><img src="assets/icon.svg" alt="" class="brand-icon"><span>DayPilot</span><small class="version-tag">2.4.0</small></div>
      <div class="sidebar-profile">
        <div class="avatar" id="avatar">U</div>
        <div><strong id="userName">User</strong><span id="syncState">Online</span></div>
      </div>
      <nav class="nav" id="sideNav">
        <button data-view="dashboard" class="nav-item active"><span class="nav-icon">◈</span>Overview</button>
        <button data-view="today" class="nav-item"><span class="nav-icon">⌂</span>Today</button>
        <button data-view="tasks" class="nav-item"><span class="nav-icon">✓</span>Tasks</button>
        <button data-view="calendar" class="nav-item"><span class="nav-icon">◷</span>Calendar</button>
        <button data-view="memory" class="nav-item"><span class="nav-icon">✦</span>Memory</button>
        <button data-view="notes" class="nav-item"><span class="nav-icon">▤</span>Notes</button>
        <button data-view="insights" class="nav-item"><span class="nav-icon">◒</span>Insights</button>
        <button data-view="assistant" class="nav-item"><span class="nav-icon">AI</span>AI Assistant</button>
        <button data-view="connections" class="nav-item"><span class="nav-icon">↗</span>Connections</button>
        <button data-view="settings" class="nav-item"><span class="nav-icon">⚙</span>Settings</button>
      </nav>
      <div class="sidebar-bottom">
        <div class="mini-ai"><span class="live-dot"></span><div><strong>DayPilot AI</strong><small id="sidebarAiStatus">Local fallback ready</small></div></div>
        <button id="logoutBtn" class="ghost full">Log out</button>
      </div>
    </aside>

    <main class="main-shell">
      <header class="topbar">
        <div class="topbar-copy"><div class="eyebrow" id="viewEyebrow">TODAY</div><h1 id="viewTitle">Good morning.</h1><p id="viewSubtitle" class="top-subtitle">Capture, focus, finish, remember.</p></div>
        <div class="top-actions"><span class="status-pill" id="networkBadge">Online</span><button id="quickCaptureBtn" class="secondary top-btn">+ Capture</button><button id="quickAddBtn" class="primary top-btn">+ Task</button></div>
      </header>

      <section id="toast" class="toast hidden"></section>
      <div id="viewToday" class="view">
        <section class="welcome-card panel hero-v21">
          <div><div class="eyebrow">DAYPILOT 2.2 · WORK INTELLIGENCE</div><h2 id="welcomeHeading">Your work, remembered.</h2><p class="muted">Your recent work, unfinished tasks and saved memory all meet here.</p></div>
          <div class="welcome-actions"><button id="planTodayBtn" class="primary">Plan my day</button><button id="reviewTodayBtn" class="secondary">Review today</button></div>
        </section>

        <section class="stats-grid four">
          <article class="stat-card"><span>Open work</span><strong id="statOpenToday">0</strong><small>active tasks</small></article>
          <article class="stat-card"><span>Done today</span><strong id="statDoneToday">0</strong><small>completed tasks</small></article>
          <article class="stat-card"><span>Focus minutes</span><strong id="statFocusToday">0</strong><small>estimated completed work</small></article>
          <article class="stat-card"><span>Memory</span><strong id="statMemoryToday">0</strong><small>stored knowledge chunks</small></article>
        </section>

        <section class="dashboard-grid">
          <article class="panel focus-panel"><div class="panel-head"><div><div class="eyebrow">NEXT ACTIONS</div><h2>Today's focus</h2></div><span class="chip" id="focusMeta">Top work</span></div><div id="focusList"></div></article>
          <article class="panel brain-panel"><div class="panel-head"><div><div class="eyebrow">DAILY CAPTURE</div><h2>What did you work on?</h2></div><span class="chip">Brain dump</span></div><p class="muted tight">Don't organize it. Just write what happened. DayPilot will turn it into useful memory.</p><form id="brainDumpForm" class="stack"><div class="split-input"><input id="brainTitle" placeholder="e.g. DayPilot backend progress"><select id="brainKind"><option value="work_log">Work update</option><option value="brain_dump">Brain dump</option><option value="learning">Learning</option><option value="blocker">Blocker</option><option value="decision">Decision</option></select></div><select id="brainProject"><option value="">No project</option></select><textarea id="brainContent" class="brain-input" rows="7" placeholder="Example: Fixed the backend, researched RAG, still need embeddings and a better mobile dashboard..."></textarea><div class="row spread"><small class="muted">Saved to your personal timeline.</small><button class="primary" type="submit">Save update</button></div></form></article>
        </section>

        <section class="dashboard-grid lower-grid">
          <article class="panel"><div class="panel-head"><div><div class="eyebrow">CONTINUITY</div><h2>Resume where you stopped</h2></div><button class="chip" data-view="memory">Open memory</button></div><div id="resumeCard" class="resume-card"></div></article>
          <article class="panel"><div class="panel-head"><div><div class="eyebrow">RECENT WORK</div><h2>Your latest updates</h2></div><button class="chip" data-view="notes">Notes</button></div><div id="recentLogs" class="timeline"></div></article>
        </section>

        <section class="panel review-panel"><div class="panel-head"><div><div class="eyebrow">END-OF-DAY MEMORY</div><h2>Today's review</h2></div><span id="reviewProvider" class="chip">Not generated</span></div><div id="todayReview" class="markdown empty">Generate a review after you finish working today. It will be saved so future AI conversations can retrieve it.</div></section>
      </div>

      <div id="viewTasks" class="view hidden">
        <section class="panel"><div class="panel-head"><div><div class="eyebrow">WORK QUEUE</div><h2>Tasks</h2></div><div class="row"><select id="taskFilter"><option value="open">Open</option><option value="done">Completed</option><option value="all">All</option></select><button id="tasksAddBtn" class="primary small">+ Task</button></div></div><div id="tasksTable" class="task-table"></div></section>
      </div>

      <div id="viewCalendar" class="view hidden">
        <section class="panel"><div class="panel-head"><div class="row"><button id="prevMonth" class="icon-btn">←</button><div><div class="eyebrow">TIME VIEW</div><h2 id="calendarTitle">Calendar</h2></div><button id="nextMonth" class="icon-btn">→</button></div><a id="icsExport" class="secondary small-link" href="#">Export .ics</a></div><div class="calendar-grid" id="calendarGrid"></div></section>
        <section class="panel"><div class="panel-head"><div><div class="eyebrow">SCHEDULE</div><h2>Add event</h2></div></div><form id="eventForm" class="form-grid"><input id="eventTitle" placeholder="Event title" required><input id="eventStart" type="datetime-local" required><input id="eventEnd" type="datetime-local" required><input id="eventLocation" placeholder="Location"><textarea id="eventDescription" placeholder="Description"></textarea><button class="primary" type="submit">Create event</button></form></section>
      </div>

      <div id="viewMemory" class="view hidden">
        <section class="memory-hero panel"><div><div class="eyebrow">PERSONAL KNOWLEDGE</div><h2>Your work memory</h2><p class="muted">Search notes, work updates, daily reviews and projects. When semantic indexing is enabled, DayPilot can combine keyword + embedding similarity with recency.</p></div><div class="memory-actions"><button id="indexMemoryBtn" class="primary">Build semantic memory</button><button id="memoryRefreshBtn" class="secondary">Refresh</button></div></section>
        <section class="stats-grid four"><article class="stat-card"><span>Work updates</span><strong id="memoryLogs">0</strong></article><article class="stat-card"><span>Notes</span><strong id="memoryNotes">0</strong></article><article class="stat-card"><span>Knowledge chunks</span><strong id="memoryChunks">0</strong></article><article class="stat-card"><span>Semantic chunks</span><strong id="memoryEmbedded">0</strong></article></section>
        <section class="memory-layout">
          <article class="panel"><div class="panel-head"><div><div class="eyebrow">RAG SEARCH</div><h2>Ask your memory</h2></div><span class="chip" id="memorySearchMode">Keyword + recency</span></div><form id="memorySearchForm" class="search-form"><input id="memoryQuery" placeholder="What did I work on with DayPilot recently?" required><button class="primary">Search</button></form><div id="memoryResults" class="memory-results"><div class="empty">Search your stored work. Results will show source and date so you can see where the context came from.</div></div></article>
          <article class="panel"><div class="panel-head"><div><div class="eyebrow">PROJECTS</div><h2>Projects</h2></div></div><form id="projectForm" class="stack compact-form"><input id="projectName" placeholder="New project name" required><input id="projectDescription" placeholder="What is this project about?"><button class="secondary" type="submit">Create project</button></form><div id="projectsList" class="project-list"></div></article>
        </section>
        <section class="panel"><div class="panel-head"><div><div class="eyebrow">MEMORY TIMELINE</div><h2>Recent work updates</h2></div><span class="muted">Latest first</span></div><div id="memoryTimeline" class="timeline"></div></section>
      </div>

      <div id="viewNotes" class="view hidden">
        <section class="notes-layout"><article class="panel"><div class="panel-head"><div><div class="eyebrow">KNOWLEDGE</div><h2>Notes</h2></div><button id="newNoteBtn" class="primary small">+ Note</button></div><div id="notesList" class="list"></div></article><article class="panel note-editor-panel"><div class="panel-head"><div><div class="eyebrow">NOTE EDITOR</div><h2 id="noteEditorTitle">New note</h2></div><button id="indexNoteBtn" type="button" class="chip">Index memory</button></div><form id="noteForm" class="stack"><input id="noteTitle" placeholder="Note title" required><input id="noteTags" placeholder="Tags, comma separated"><textarea id="noteContent" class="note-editor" placeholder="Write notes here..."></textarea><div class="row spread"><button class="secondary" id="aiNoteBtn" type="button">Prepare notes with AI</button><button class="primary" type="submit">Save note</button></div></form></article></section>
      </div>

      <?php require __DIR__ . '/views/control-center.html'; ?>

      <div id="viewInsights" class="view hidden">
        <section class="stats-grid four"><article class="stat-card"><span>Completion rate</span><strong id="insightRate">0%</strong></article><article class="stat-card"><span>Focus minutes</span><strong id="insightFocus">0</strong></article><article class="stat-card"><span>Overdue</span><strong id="insightOverdue">0</strong></article><article class="stat-card"><span>Work updates</span><strong id="insightLogs">0</strong></article></section>
        <section class="insights-grid"><article class="panel"><div class="panel-head"><div><div class="eyebrow">30-DAY TREND</div><h2>Completed work</h2></div><span class="muted">Recorded task completions</span></div><canvas id="analyticsChart" height="220"></canvas></article><article class="panel"><div class="panel-head"><div><div class="eyebrow">PRIORITIES</div><h2>Work mix</h2></div></div><div id="priorityAnalytics" class="priority-bars"></div></article></section>
        <section class="panel"><div class="panel-head"><div><div class="eyebrow">PATTERNS</div><h2>What DayPilot can see</h2></div></div><div id="workInsights" class="insight-cards"></div></section>
      </div>

      <div id="viewAssistant" class="view hidden">
        <section class="panel assistant-panel"><div class="panel-head"><div><div class="eyebrow">CONTEXT-AWARE AI</div><h2>DayPilot AI</h2><p class="muted">Ask about your current work, prior updates, unfinished tasks, learning notes or next steps.</p></div><span id="aiStatus" class="chip">Checking AI</span></div><div class="prompt-row"><button class="prompt-chip" data-prompt="Where did I stop yesterday?">Resume yesterday</button><button class="prompt-chip" data-prompt="What did I accomplish this week?">Weekly recap</button><button class="prompt-chip" data-prompt="What should I focus on today?">Plan today</button><button class="prompt-chip" data-prompt="Test me on what I learned recently.">Revise me</button></div><div id="fullChat" class="chat-scroll big"></div><form id="fullChatForm" class="chat-form"><button type="button" class="icon-btn mic-btn" data-target="fullChatInput" title="Voice input">🎙</button><textarea id="fullChatInput" rows="2" placeholder="Tell DayPilot what you need…"></textarea><button class="primary">Send</button></form><div class="chat-foot"><span>RAG memory is added automatically when relevant.</span><span id="chatSources">0 memory sources</span></div></section>
      </div>

      <div id="viewSettings" class="view hidden"><section class="settings-grid"><article class="panel"><div class="panel-head"><div><div class="eyebrow">DEVICE</div><h2>Notifications</h2></div><span id="pushState" class="chip">Not enabled</span></div><p class="muted">Enable push so reminders can reach your phone or laptop.</p><button id="enablePushBtn" class="primary">Enable notifications</button></article><article class="panel"><div class="panel-head"><div><div class="eyebrow">REMINDERS</div><h2>Schedule a reminder</h2></div><span class="chip">5-minute cron</span></div><form id="reminderForm" class="stack"><select id="reminderTask"><option value="">Choose a task</option></select><input id="reminderWhen" type="datetime-local" required><input id="reminderTitle" placeholder="Reminder message" required><button class="secondary" type="submit">Schedule reminder</button></form><div id="reminderList" class="list"></div></article><article class="panel"><div class="panel-head"><div><div class="eyebrow">WORKSPACE</div><h2>Environment</h2></div></div><div class="settings-list"><div><span>Account</span><strong id="settingsEmail">—</strong></div><div><span>Timezone</span><strong id="settingsTimezone">Asia/Kolkata</strong></div><div><span>AI providers</span><strong id="settingsAi">Checking…</strong></div><div><span>RAG semantic queries</span><strong id="settingsRag">Off</strong></div><div><span>Offline mode</span><strong>Enabled</strong></div></div></article></section></div>
    </main>

    <nav class="mobile-nav" id="mobileNav"><button data-view="dashboard"><span>◈</span><small>Overview</small></button><button id="mobileCaptureBtn"><span>＋</span><small>Capture</small></button><button data-view="tasks"><span>✓</span><small>Tasks</small></button><button data-view="memory"><span>✦</span><small>Memory</small></button><button data-view="assistant"><span>AI</span><small>Ask</small></button></nav>
  </div>

  <div id="captureModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-modal></div>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="captureTitle">
      <div class="panel-head"><div><div class="eyebrow">QUICK CAPTURE</div><h2 id="captureTitle">Add a work update</h2></div><button class="icon-btn" data-close-modal aria-label="Close">×</button></div>
      <form id="captureModalForm" class="stack"><input id="captureTitleInput" placeholder="What were you working on?" required><select id="captureKind"><option value="work_log">Work update</option><option value="brain_dump">Brain dump</option><option value="learning">Learning</option><option value="blocker">Blocker</option><option value="decision">Decision</option></select><select id="captureProject"><option value="">No project</option></select><textarea id="captureContent" class="brain-input" rows="9" placeholder="Write freely…"></textarea><div class="row spread"><button type="button" class="secondary" data-close-modal>Cancel</button><button class="primary" type="submit">Save update</button></div></form>
    </section>
  </div>
</div>
<script src="assets/app.js?v=2.4.0" defer></script>
<script src="assets/connections.js?v=2.4.0" defer></script>
</body>
</html>
