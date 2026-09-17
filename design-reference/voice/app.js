"use strict";

const views = {
  command: {
    title: "Voice Command Centre",
    subtitle: "Every conversation. A clear next step.",
    action: "Make a call",
    metrics: [
      ["Calls today", "1,248", "Sample operational total"],
      ["Answer rate", "94.2%", "Answered / eligible attempts"],
      ["AI resolution", "68%", "Defined completed outcomes"],
      ["Callbacks due", "18", "Requires attention"]
    ]
  },
  live: {
    title: "Live Operations",
    subtitle: "See the queue. Support the conversation.",
    action: "Open dialler",
    metrics: [
      ["Active calls", "24", "Illustrative live state"],
      ["Waiting", "7", "Across three queues"],
      ["Available agents", "12", "Human agents"],
      ["Longest wait", "01:42", "Billing queue"]
    ]
  },
  studio: {
    title: "AI Voice Studio",
    subtitle: "Build confidence before the first call.",
    action: "Run rehearsal",
    metrics: [
      ["Configured agents", "8", "Sample configuration"],
      ["Published", "5", "Versioned releases"],
      ["Checks passed", "18", "Sample test results"],
      ["Needs review", "2", "Publishing blocked"]
    ]
  },
  campaigns: {
    title: "Campaigns & Growth",
    subtitle: "Turn outreach into measurable outcomes.",
    action: "New campaign",
    metrics: [
      ["Connected", "842", "Selected campaign period"],
      ["Qualified", "126", "Defined disposition"],
      ["Bookings", "54", "Owner-API confirmed"],
      ["Cost per booking", "₹86", "Illustrative estimate"]
    ]
  },
  intelligence: {
    title: "Conversation Intelligence",
    subtitle: "Find the evidence behind every next step.",
    action: "Search conversations",
    metrics: [
      ["Calls reviewed", "486", "Selected period"],
      ["Commitments found", "72", "Includes suggestions"],
      ["Follow-ups overdue", "9", "Confirmed actions"],
      ["Needs review", "14", "Human review queue"]
    ]
  },
  network: {
    title: "Network & Usage",
    subtitle: "Keep every conversation connected.",
    action: "Test connection",
    metrics: [
      ["Connection success", "99.2%", "Eligible attempts"],
      ["Concurrent calls", "24 / 80", "Configured capacity"],
      ["Media latency", "148 ms", "Illustrative measurement"],
      ["Usage today", "₹3,240", "Estimated charges"]
    ]
  }
};

function button(label, primary = false) {
  return `<button class="button ${primary ? "primary" : ""}"
    data-demo="${label}">${label}</button>`;
}
function wave() {
  return `<div class="wave" role="img"
    aria-label="Decorative sample voice waveform">
    ${Array.from({length: 48}, (_, i) => {
      const height = 14 + Math.abs(Math.sin(i * .63)) * 54;
      return `<i style="--height:${height}px;--delay:${i * -.04}s"></i>`;
    }).join("")}
  </div>`;
}
function bar(label, value, width) {
  return `<div class="bar-row">
    <div class="bar-label"><span>${label}</span><strong>${value}</strong></div>
    <div class="bar-track"><div class="bar-fill"
      style="--width:${width}%"></div></div>
  </div>`;
}
function row(title, detail, status, tone = "") {
  return `<div class="row"><div><strong>${title}</strong>
    <p class="muted">${detail}</p></div>
    <span class="badge ${tone}">${status}</span></div>`;
}
function card(title, body) {
  return `<section class="card"><div class="card-head">
    <h2>${title}</h2></div>${body}</section>`;
}

const screens = {
  command: () => `
    <div class="grid two">
      <section class="card hero">
        <div class="card-head">
          <div><h2>Your voice operations, at a glance</h2>
            <p class="muted">Illustrative operations overview</p></div>
          <span class="badge">Sample</span>
        </div>
        ${wave()}
        <div class="hero-stats">
          <div><strong>24</strong><span>Active calls</span></div>
          <div><strong>8</strong><span>AI sessions</span></div>
          <div><strong>12</strong><span>Available agents</span></div>
        </div>
        <div class="brief">
          <h3>AI operations brief</h3>
          <p>12 unanswered calls have no completed callback.
            Review the callback queue.</p>
          <div class="actions">${button("Review callbacks")}</div>
        </div>
      </section>
      ${card("Needs your attention", `
        ${row("Unanswered calls", "12 callers awaiting follow-up", "Review", "amber")}
        ${row("Billing queue", "Oldest wait 01:42", "Monitor", "amber")}
        ${row("Agent readiness", "Two tests require review", "2 checks", "neutral")}
        <div class="actions">${button("Open priorities", true)}</div>
      `)}
    </div>
    <div class="grid three">
      ${card("Call outcomes", `
        ${bar("Resolved", "68%", 68)}
        ${bar("Follow-up", "22%", 22)}
        ${bar("Handed over", "10%", 10)}
      `)}
      ${card("Queue activity", `
        ${row("Billing", "Four callers waiting", "4")}
        ${row("Sales", "Two callers waiting", "2")}
        ${row("Support", "One caller waiting", "1")}
      `)}
      ${card("Connected workflows", `
        ${row("Aicountly Calendar", "26 confirmed bookings", "Demo")}
        ${row("Aicountly CRM", "14 confirmed follow-ups", "Demo")}
        ${row("Aicountly Pay", "Connection required", "Not configured", "neutral")}
      `)}
    </div>`,

  live: () => `
    <div class="grid three">
      ${card("Queues & agents", `
        ${row("Billing", "4 waiting · 2 in call", "Busy", "amber")}
        ${row("Sales", "2 waiting · 1 in call", "Active")}
        ${row("Support", "1 waiting · 3 in call", "Active")}
        <h3 style="margin-top:24px">Team availability</h3>
        ${row("Arjun Mehta", "Extension 1001", "Available")}
        ${row("Priya Nair", "Extension 1002", "In call", "amber")}
        ${row("Neha Kapoor", "Extension 1003", "Available")}
      `)}
      ${card("Selected call", `
        <div class="call-title">
          <h2>Priya Sharma</h2>
          <p class="muted">+91 •••••• 4821 · Sample call</p>
          <span class="badge">English ↔ Hindi</span>
        </div>
        ${wave()}
        <div class="call-controls">
          ${button("Mute")}${button("Hold")}${button("Transfer")}
          <button class="button danger" data-demo="End call">End call</button>
        </div>
        <div class="transcript">
          <h3>Transcript preview</h3>
          <p><span class="timestamp">03:21 · Caller</span><br>
            क्या मेरी अगली मीटिंग शुक्रवार को हो सकती है?</p>
          <p class="muted">Translation: Can my next meeting be on Friday?</p>
          <p><span class="timestamp">03:22 · Agent</span><br>
            Let me check the available slots for you.</p>
        </div>
      `)}
      <div class="stack">
        ${card("AI Copilot", `
          <div class="soft">
            <h3>Suggested intent</h3><p>Reschedule appointment</p>
            <p class="muted">Confirm the request before taking action.</p>
          </div>
          <div class="soft" style="margin-top:12px">
            <h3>Calendar availability</h3>
            <p>Friday · 11:30 AM · Sample result</p>
            <p class="muted">Recheck through the live API before booking.</p>
          </div>
          <div class="actions">${button("Prepare booking", true)}</div>
        `)}
        ${card("Handover brief", `
          <p>Caller requested a specialist to reschedule an appointment.</p>
          <p class="muted">Pending: confirm the final time with the caller.</p>
          ${button("Accept handover", true)}
        `)}
      </div>
    </div>`,

  studio: () => `
    <div class="grid three">
      ${card("Asha · Appointment Assistant", `
        <div class="stack">
          <span class="badge amber">Version 3 · Draft</span>
          <label class="field">Agent name<input value="Asha"></label>
          <label class="field">Tone
            <select><option>Warm and professional</option>
              <option>Concise and formal</option></select>
          </label>
          <div class="soft"><h3>Languages</h3>
            <p>Hindi · English · Punjabi</p>
            <p class="muted">Subject to configured speech capabilities.</p>
          </div>
          ${button("Preview voice")}
          <div>
            ${row("Check availability", "Live Calendar API", "Allowed")}
            ${row("Create booking", "Caller confirmation", "Confirm", "amber")}
            ${row("Issue refund", "Specialist required", "Handover", "neutral")}
          </div>
        </div>
      `)}
      ${card("Call-flow preview", `
        <div class="flow">
          <div class="flow-node">Greeting and disclosure</div>
          <span class="flow-arrow" aria-hidden="true">↓</span>
          <div class="flow-node">Understand intent</div>
          <span class="flow-arrow" aria-hidden="true">↓</span>
          <div class="flow-node">Check live availability</div>
          <span class="flow-arrow" aria-hidden="true">↓</span>
          <div class="flow-node">Confirm with caller</div>
          <span class="flow-arrow" aria-hidden="true">↓</span>
          <div class="flow-node">Create through Calendar API</div>
          <span class="flow-arrow" aria-hidden="true">↓</span>
          <div class="flow-node">Recap confirmed outcome</div>
        </div>
        <p class="muted" style="margin-top:16px">
          Production editor must include branches and failure handling.
        </p>
      `)}
      <div class="stack">
        ${card("Rehearsal room", `
          <span class="badge neutral">Simulated conversation</span>
          <p style="margin-top:16px">Scenario: caller changes date mid-sentence.</p>
          <blockquote class="quote">
            “Actually, can we do next Friday instead?”
          </blockquote>
          <div class="actions">${button("Run test", true)}</div>
        `)}
        ${card("Readiness checks", `
          ${row("Flow validation", "Sample result", "18 passed")}
          ${row("Holiday hours", "Answer missing", "Review", "amber")}
          ${row("Handover destination", "Not assigned", "Review", "amber")}
          <button class="button full" disabled>Publish after checks pass</button>
        `)}
      </div>
    </div>`,

  campaigns: () => `
    <div class="grid two">
      ${card("Campaign overview", `
        <div class="table-wrap">
          <table>
            <caption class="sr-only">Sample campaigns</caption>
            <thead><tr><th>Campaign</th><th>Connected</th>
              <th>Outcome</th><th>Status</th></tr></thead>
            <tbody>
              <tr><td><strong>Demo follow-ups</strong>
                <small>Requested enquiries</small></td><td>426</td>
                <td>38 bookings</td><td><span class="badge">Running</span></td></tr>
              <tr><td><strong>Appointment reminders</strong>
                <small>Confirmed bookings</small></td><td>318</td>
                <td>286 confirmations</td><td><span class="badge">Running</span></td></tr>
              <tr><td><strong>Renewal conversations</strong>
                <small>Audience under review</small></td><td>—</td>
                <td>—</td><td><span class="badge neutral">Draft</span></td></tr>
            </tbody>
          </table>
        </div>
        <div class="actions">${button("Manage campaigns")}</div>
      `)}
      ${card("AI campaign planner", `
        <span class="badge amber">Draft only</span>
        <p style="margin-top:16px">Follow up with customers who requested a demo.</p>
        <div class="soft">
          <p><strong>Suggested languages:</strong> English and Hindi</p>
          <p class="muted">Audience, eligibility and schedule require review.</p>
        </div>
        <div class="actions">${button("Review audience", true)}</div>
      `)}
    </div>
    <div class="grid two">
      ${card("Conversion funnel", `
        ${bar("Attempted", "1,200 · 100%", 100)}
        ${bar("Connected", "842 · 70.2% of attempts", 70.2)}
        ${bar("Qualified", "126 · 10.5% of attempts", 10.5)}
        ${bar("Booked", "54 · 4.5% of attempts", 4.5)}
      `)}
      ${card("Launch readiness", `
        ${row("Audience eligibility", "Check applicable purpose and permission", "Review", "amber")}
        ${row("Suppression policy", "Recheck before dispatch", "Configured")}
        ${row("Calling window", "Tenant policy required", "Configured")}
        ${row("Script preview", "Awaiting review", "Pending", "amber")}
        <div class="actions">${button("Review launch checks")}</div>
      `)}
    </div>`,

  intelligence: () => `
    <div class="grid three">
      ${card("Call recordings", `
        ${row("Arjun Mehta", "Quote follow-up · 06:18", "Selected")}
        ${row("Priya Nair", "Service enquiry · 04:52", "Review", "neutral")}
        ${row("Rohit Sharma", "Pricing discussion · 08:11", "Review", "amber")}
        ${row("Neha Kapoor", "Contract terms · 09:22", "Review", "neutral")}
        <div class="actions">${button("Filter recordings")}</div>
      `)}
      ${card("Conversation evidence", `
        <h3>Arjun Mehta · Quote follow-up</h3>
        <p class="muted">Recording preview · Sample conversation</p>
        ${wave()}
        ${button("Play recording")}
        <div class="transcript" style="margin-top:18px">
          <p><span class="timestamp">04:12 · Caller</span></p>
          <blockquote class="quote">
            Please send the revised quotation by Friday.
          </blockquote>
          <p style="margin-top:16px">
            <span class="timestamp">04:28 · Agent</span><br>
            I will share the revised quotation by Friday.
          </p>
        </div>
      `)}
      <div class="stack">
        ${card("Commitment ledger", `
          <h3>Send revised quotation</h3>
          <dl class="detail-list">
            <dt>Owner</dt><dd>Neha</dd>
            <dt>Due</dt><dd>Friday · Confirm date</dd>
            <dt>Evidence</dt><dd>04:12 / 04:28</dd>
            <dt>Status</dt><dd><span class="badge amber">Suggested</span></dd>
          </dl>
          <div class="actions">${button("Confirm task", true)}${button("Edit")}</div>
        `)}
        ${card("AI summary", `
          <p>Customer requested an updated quotation.
            The agent committed to sending it by Friday.</p>
          <p class="muted">Verify against the transcript before creating a task.</p>
          ${button("Create in Aicountly CRM")}
        `)}
      </div>
    </div>`,

  network: () => `
    <div class="grid two">
      ${card("Connection health", `
        <div class="table-wrap"><table>
          <caption class="sr-only">Sample connection status</caption>
          <thead><tr><th>Route</th><th>Status</th><th>Calls</th></tr></thead>
          <tbody>
            <tr><td>Primary SIP</td><td><span class="badge">Healthy</span></td><td>18</td></tr>
            <tr><td>Backup SIP</td><td><span class="badge neutral">Standby</span></td><td>0</td></tr>
            <tr><td>Browser calling</td><td><span class="badge">Healthy</span></td><td>6</td></tr>
          </tbody>
        </table></div>
        <div class="actions">${button("Inspect routes")}</div>
      `)}
      ${card("Usage breakdown", `
        ${bar("Voice minutes", "₹2,180", 67.3)}
        ${bar("AI processing", "₹820", 25.3)}
        ${bar("Number rental allocation", "₹240", 7.4)}
        <div class="row"><strong>Total estimate</strong><strong>₹3,240</strong></div>
      `)}
    </div>
    <div class="grid three">
      ${card("Your numbers", `
        ${row("+91 •••••• 4401", "Sales IVR", "Active")}
        ${row("+91 •••••• 7723", "Support IVR", "Active")}
        ${row("+91 •••••• 1189", "Billing IVR", "Active")}
      `)}
      ${card("Aicountly integrations", `
        ${row("Calendar", "Read and write through live API", "Demo")}
        ${row("CRM", "Read and write through live API", "Demo")}
        ${row("Pay", "Integration pending", "Not configured", "neutral")}
        ${row("Lobby", "Integration pending", "Not configured", "neutral")}
      `)}
      ${card("Capacity & budget", `
        ${bar("Concurrent call capacity", "24 / 80", 30)}
        ${bar("Monthly budget", "₹18,420 / ₹30,000", 61.4)}
        <div class="soft">
          <h3>Backup route test due</h3>
          <p class="muted">Validate fallback routing before relying on it.</p>
          ${button("Test backup route")}
        </div>
      `)}
    </div>`
};

function showReferenceDialog(label) {
  document.querySelector("#dialog-title").textContent = label;
  document.querySelector("#dialog-copy").textContent =
    "This is a design-reference interaction. The production application " +
    "must connect this action to an authorised backend operation and show " +
    "its real result. No call or external write was performed.";
  document.querySelector("#demo-dialog").showModal();
}

function render() {
  const candidate = location.hash.slice(1);
  const key = Object.hasOwn(views, candidate) ? candidate : "command";
  const view = views[key];

  document.querySelector("#page-title").textContent = view.title;
  document.querySelector("#page-subtitle").textContent = view.subtitle;
  const action = document.querySelector("#primary-action");
  action.textContent = view.action;
  action.dataset.demo = view.action;

  document.querySelectorAll("[data-view]").forEach(link => {
    if (link.dataset.view === key) link.setAttribute("aria-current", "page");
    else link.removeAttribute("aria-current");
  });

  document.querySelector("#metrics").innerHTML = view.metrics.map(metric => `
    <article class="metric">
      <p class="metric-label">${metric[0]}</p>
      <p class="metric-value">${metric[1]}</p>
      <p class="metric-note">${metric[2]}</p>
    </article>`).join("");

  document.querySelector("#dashboard-content").innerHTML = screens[key]();
}

document.addEventListener("click", event => {
  const target = event.target.closest("[data-demo]");
  if (target) showReferenceDialog(target.dataset.demo);
});

document.querySelector("#global-search").addEventListener("keydown", event => {
  if (event.key === "Enter") {
    event.preventDefault();
    showReferenceDialog("Search");
  }
});

window.addEventListener("hashchange", render);
render();
