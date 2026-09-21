<?php declare(strict_types=1); ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#121a2b"><meta name="description" content="DayPilot — your AI work assistant, calendar, notes, analytics and reminders.">
<link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="assets/icon.svg">
<title>DayPilot — Personal Work Assistant</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div id="app">
  <div id="authView" class="center-shell">
    <section class="auth-card">
      <div class="brand"><img src="assets/icon.svg" alt="" class="brand-icon"><span>DayPilot</span></div>
      <p class="tagline">One place to plan, execute and remember your work.</p>
      <div id="authError" class="alert error hidden"></div>
      <form id="loginForm" class="stack">
        <label>Email<input id="loginEmail" type="email" autocomplete="email" required></label>
        <label>Password<input id="loginPassword" type="password" autocomplete="current-password" required minlength="8"></label>
        <button class="primary" type="submit">Log in</button>
      </form>
      <button id="showRegister" class="link-button">Create an account</button>
      <form id="registerForm" class="stack hidden">
        <label>Name<input id="regName" autocomplete="name" required></label>
        <label>Email<input id="regEmail" type="email" autocomplete="email" required></label>
        <label>Password<input id="regPassword" type="password" autocomplete="new-password" required minlength="8"></label>
        <button class="primary" type="submit">Create account</button>
      </form>
      <button id="showLogin" class="link-button hidden">Back to login</button>
    </section>
  </div>

  <div id="appView" class="app-shell hidden">
    <aside class="sidebar">
      <div class="brand side-brand"><img src="assets/icon.svg" alt="" class="brand-icon"><span>DayPilot</span></div>
      <div class="profile-card"><strong id="userName">User</strong><span id="syncState">Online</span></div>
      <nav id="sideNav" class="nav">
        <button data-view="today" class="nav-item active">Today</button>
        <button data-view="calendar" class="nav-item">Calendar</button>
        <button data-view="tasks" class="nav-item">Tasks</button>
        <button data-view="notes" class="nav-item">Notes</button>
        <button data-view="analytics" class="nav-item">Analytics</button>
        <button data-view="assistant" class="nav-item">AI Assistant</button>
        <button data-view="settings" class="nav-item">Settings</button>
      </nav>
      <button id="logoutBtn" class="ghost full">Log out</button>
    </aside>

    <main class="main-shell">
      <header class="topbar">
        <div><div class="eyebrow" id="viewEyebrow">TODAY</div><h1 id="viewTitle">Make progress, not just plans.</h1></div>
        <div class="top-actions"><span class="status-pill" id="networkBadge">Online</span><button id="quickAddBtn" class="primary small">+ Task</button></div>
      </header>

      <section id="toast" class="toast hidden"></section>

      <div id="viewToday" class="view">
        <section class="hero-grid">
          <div class="panel focus-panel"><div class="panel-head"><h2>Today's focus</h2><span class="chip">Top 5</span></div><div id="focusList"></div><div class="panel-footer"><button id="planTodayBtn" class="secondary">Plan my day</button></div></div>
          <div class="panel"><div class="panel-head"><h2>Quick capture</h2><span class="muted">Fastest path to action</span></div><form id="quickTaskForm" class="quick-form"><input id="quickTitle" placeholder="What needs to get done?" required><select id="quickPriority"><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select><input id="quickDue" type="datetime-local"><input id="quickMinutes" type="number" min="5" step="5" placeholder="Minutes"><button class="primary">Add</button></form><div class="panel-head compact"><h3>Open work</h3><span id="openCount" class="muted">0</span></div><div id="openTaskList" class="list"></div></div>
        </section>
        <section class="panel"><div class="panel-head"><h2>Ask DayPilot</h2><button class="chip" data-view="assistant">Open assistant</button></div><div class="assistant-inline"><div id="miniChat" class="chat-scroll"></div><form id="miniChatForm" class="chat-form"><button type="button" class="icon-btn mic-btn" data-target="miniChatInput" title="Voice input">🎙️</button><input id="miniChatInput" placeholder='Try “plan today” or “schedule my DSA work tomorrow”'><button class="primary">Send</button></form></div></section>
      </div>

      <div id="viewCalendar" class="view hidden"><section class="panel"><div class="panel-head"><div class="row"><button id="prevMonth" class="icon-btn">←</button><h2 id="calendarTitle">Calendar</h2><button id="nextMonth" class="icon-btn">→</button></div><a id="icsExport" class="secondary small-link" href="#">Export .ics</a></div><div class="calendar-grid" id="calendarGrid"></div></section><section class="panel"><div class="panel-head"><h2>Add calendar event</h2></div><form id="eventForm" class="form-grid"><input id="eventTitle" placeholder="Event title" required><input id="eventStart" type="datetime-local" required><input id="eventEnd" type="datetime-local" required><input id="eventLocation" placeholder="Location"><textarea id="eventDescription" placeholder="Description"></textarea><button class="primary">Create event</button></form></section></div>

      <div id="viewTasks" class="view hidden"><section class="panel"><div class="panel-head"><h2>Tasks</h2><div class="row"><select id="taskFilter"><option value="open">Open</option><option value="done">Completed</option><option value="all">All</option></select></div></div><div id="tasksTable" class="task-table"></div></section></div>

      <div id="viewNotes" class="view hidden"><section class="two-col"><div class="panel"><div class="panel-head"><h2>Notes</h2><button id="newNoteBtn" class="primary small">+ Note</button></div><div id="notesList" class="list"></div></div><div class="panel"><div class="panel-head"><h2 id="noteEditorTitle">New note</h2><span class="chip">AI-ready</span></div><form id="noteForm" class="stack"><input id="noteTitle" placeholder="Note title" required><input id="noteTags" placeholder="Tags, comma separated"><textarea id="noteContent" class="note-editor" placeholder="Write notes here..."></textarea><div class="row"><button class="primary" type="submit">Save note</button><button id="aiNoteBtn" type="button" class="secondary">Prepare notes with AI</button></div></form></div></section></div>

      <div id="viewAnalytics" class="view hidden"><section class="stats-grid"><div class="stat-card"><span>Completion rate</span><strong id="statRate">0%</strong></div><div class="stat-card"><span>Focus minutes</span><strong id="statFocus">0</strong></div><div class="stat-card"><span>Overdue</span><strong id="statOverdue">0</strong></div><div class="stat-card"><span>Open tasks</span><strong id="statOpen">0</strong></div></section><section class="panel"><div class="panel-head"><h2>Last 30 days</h2><span class="muted">Completed work and focus time</span></div><canvas id="analyticsChart" height="180"></canvas><div id="priorityAnalytics" class="priority-bars"></div></section></div>

      <div id="viewAssistant" class="view hidden"><section class="panel assistant-panel"><div class="panel-head"><div><h2>DayPilot AI</h2><p class="muted">Understands your work, uses tools, and changes your plan only through validated actions.</p></div><span id="aiStatus" class="chip">Checking AI</span></div><div id="fullChat" class="chat-scroll big"></div><form id="fullChatForm" class="chat-form"><button type="button" class="icon-btn mic-btn" data-target="fullChatInput" title="Voice input">🎙️</button><textarea id="fullChatInput" rows="2" placeholder="Tell me what you need. For example: break my ML project into 3 sessions, schedule them this week, and create a reminder."></textarea><button class="primary">Send</button></form></section></div>

      <div id="viewSettings" class="view hidden"><section class="two-col"><div class="panel"><div class="panel-head"><h2>Notifications</h2><span id="pushState" class="chip">Not enabled</span></div><p class="muted">Enable push so scheduled reminders can reach this device even when DayPilot is not open.</p><button id="enablePushBtn" class="primary">Enable notifications</button></div><div class="panel reminder-panel"><div class="panel-head"><h2>Reminders</h2><span class="chip">5-minute cron</span></div><form id="reminderForm" class="stack"><select id="reminderTask"><option value="">Choose a task</option></select><input id="reminderWhen" type="datetime-local" required><input id="reminderTitle" placeholder="Reminder message" required><button class="secondary" type="submit">Schedule reminder</button></form><div id="reminderList" class="list"></div></div><div class="panel"><div class="panel-head"><h2>Workspace</h2></div><div class="settings-list"><div><span>Account</span><strong id="settingsEmail">—</strong></div><div><span>Timezone</span><strong id="settingsTimezone">Asia/Kolkata</strong></div><div><span>AI model</span><strong>Gemini 3.8 Flash</strong></div><div><span>Offline mode</span><strong>Enabled</strong></div></div></div></section></div>
    </main>

    <nav class="mobile-nav" id="mobileNav"><button data-view="today">Today</button><button data-view="calendar">Calendar</button><button data-view="tasks">Tasks</button><button data-view="notes">Notes</button><button data-view="assistant">AI</button></nav>
  </div>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
