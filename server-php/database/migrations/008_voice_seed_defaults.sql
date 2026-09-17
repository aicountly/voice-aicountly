-- Migration 008: Dispositions that exist in every calling business.
--
-- Seeded with cmp_id = 0, which means "fleet default". CallsController reads a
-- company's own rows and falls back to these, so a brand-new company can
-- disposition a call on day one without an administrator configuring a list
-- first. A company that adds its own rows stops seeing these.

INSERT INTO voice_dispositions (cmp_id, code, label, category, requires_note, sort_order)
VALUES
    (0, 'resolved',        'Resolved',                'resolved',       FALSE, 10),
    (0, 'follow_up',       'Follow-up needed',        'follow_up',      TRUE,  20),
    (0, 'callback',        'Callback requested',      'follow_up',      FALSE, 30),
    (0, 'qualified',       'Qualified',               'qualified',      FALSE, 40),
    (0, 'not_interested',  'Not interested',          'not_interested', FALSE, 50),
    (0, 'wrong_number',    'Wrong number',            'other',          FALSE, 60),
    (0, 'do_not_call',     'Asked not to be called',  'not_interested', FALSE, 70),
    (0, 'no_answer',       'No answer',               'other',          FALSE, 80),
    (0, 'voicemail_left',  'Voicemail left',          'other',          FALSE, 90),
    (0, 'escalated',       'Escalated',               'follow_up',      TRUE,  100)
ON CONFLICT (cmp_id, code) DO NOTHING;

-- The default quality rubric. Its deterministic criteria are things the code
-- can prove from the call record; the model-assessed ones are marked as such so
-- the screen never presents an opinion as a measurement.
INSERT INTO voice_quality_rubrics (cmp_id, rubric_key, name, criteria)
VALUES (0, 'default', 'Default call quality', '[
    {"key": "greeting_present",      "label": "Greeting present",        "kind": "deterministic", "weight": 10},
    {"key": "disclosure_played",     "label": "Recording/AI disclosure", "kind": "deterministic", "weight": 20},
    {"key": "confirmation_requested","label": "Caller confirmation requested before action", "kind": "deterministic", "weight": 25},
    {"key": "handover_completed",    "label": "Handover completed",      "kind": "deterministic", "weight": 15},
    {"key": "clarity",               "label": "Explanation was clear",   "kind": "model",         "weight": 15},
    {"key": "next_step",             "label": "Next step was agreed",    "kind": "model",         "weight": 15}
]'::jsonb)
ON CONFLICT (cmp_id, rubric_key) DO NOTHING;
