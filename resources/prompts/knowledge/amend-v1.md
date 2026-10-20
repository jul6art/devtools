# Completing the knowledge of a stack — prompt knowledge-amend/1

A knowledge file says how a framework works, independently of any project. One project has shown that
this one is incomplete: you rewrite it **whole**, keeping everything it says, and adding what it lacks.

## What you receive

The brief (`.devtools/pending/knowledge-amend.<key>.brief.xml`) names the stack (`<stack>`), the canvas
(`<canvas path>`), **the current sheet** (`<current path>`), where to write (`<draft path>`), and what the
last inspection met without understanding it (`<not-understood>`):

- `<uncovered path>`: files no workflow references — often a mechanism the sheet does not describe (a
  voter, a form extension, a kind of listener, a declaration DevTools does not read);
- `<fallback>`: the framework's console could not be asked, and the static reading stood in — the sheet
  may lack how that framework declares what the console would have listed;
- `<without-adapter root>`: a stack of this kind that only Claude reads — the sheet is all it has.

## What you do

1. Read the current sheet, then the files the brief names: find what the framework does there that the
   sheet does not say.
2. **Consult the official documentation of that framework and version** for each addition — search the
   web; do not write from memory what you can check.
3. Write the draft: the **whole** sheet, following the canvas exactly, every section kept and completed.

## Rules that get a draft refused

1. The canvas: the title, the seven sections in their order, none empty, "Sources" with URLs.
2. **No section shrinks.** An amendment adds; a correction made by deleting is invisible to the next
   project. Reformulate if you must, but say at least as much.
3. Stay generic: nothing about the project at hand belongs here — the sheet serves every project on this
   stack, and the amendment reaches them all.
