\# Problem statement



Environment:

\- Z-Push 2.7.6

\- BackendCombined with IMAP mail

\- iPhone Mail app via ActiveSync

\- Small folders sync fine

\- Large folders like "Bestellungen" and likely "Sent" trigger loop behavior

\- The same suspect mails sync fine when moved into small test folders

\- IMAP shows the mails normally, so raw mail corruption is unlikely



Observed behavior:

\- Large folders start to "drip-feed" messages

\- Z-Push enters Mobile loop detected mode

\- Earlier logs showed RFC822 validation and timezone conversion issues

\- Current code inspection suggests the main problem may be in:

&#x20; - backend/imap/imap.php

&#x20; - lib/default/diffbackend/exportchangesdiff.php

&#x20; - lib/core/loopdetection.php



Evidence:

\- Bestellungen triggers loop detection around internal message ids 340, 342, 347

\- These mapped to normal mails (iFixit, Akku-King, DHL)

\- The same mails are visible normally in Bestellungen-Looptest / Bestellungen-Quarantaene

\- Therefore the issue likely depends on folder/state/diff context, not the raw mails themselves



Task:

Please perform root-cause analysis first, not a blind fix.

Focus on:

1\. GetMessageList()

2\. ExportChangesDiff->InitializeExporter() / Synchronize()

3\. StatMessage() / GetMessage()

4\. LoopDetection as a consequence, not necessarily as primary cause



Questions:

\- Why do small folders work while large folders fail?

\- Is there a race/inconsistency between GetMessageList(), StatMessage(), and GetMessage()?

\- Is the diff engine unstable for large IMAP folders?

\- Are there code paths that can repeatedly re-emit the same changes and trigger loop detection?

\- Propose the smallest safe patch and explain the risk.

