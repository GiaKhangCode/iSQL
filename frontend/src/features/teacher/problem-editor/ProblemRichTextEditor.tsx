import {Select} from "@/shared/ui/Select";
import { markdownToolbarEdit } from "@/shared/lib/markdown-richtext/markdownToolbar";
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { FileUp, Bold, Code2, Heading2, Heading3, Italic, Underline, List, ListOrdered, Redo2, Undo2, Link2, Minus, Maximize2, ChevronDown, AlignVerticalSpaceAround } from "lucide-react";
import { TranslatedText } from "@/shared/ui/TranslatedText";
import { useLanguage } from "@/shared/context/LanguageContext";
import { editorHtmlToMarkdown, markdownToEditorHtml, safeHref } from "@/shared/lib/markdown-richtext/problemRichText";
import { GlidingTooltipGroup } from "@/shared/ui/GlidingTooltip";
import { adjustSelectedFontSize, emptyInlineFormats, readInlineFormats, toggleInlineCode } from "@/shared/lib/markdown-richtext/richTextFormatting";
import {LINE_SPACING, PARAGRAPH_SPACING, readParagraphSpacing, setParagraphSpacing, type ParagraphSpacing} from "@/shared/lib/markdown-richtext/richTextLayout";

function getTextSize(editor: HTMLElement, selection: Selection | null): number | "mixed" | null {
  if (!selection || !selection.rangeCount || selection.isCollapsed) return null;
  const range = selection.getRangeAt(0);
  if (!editor.contains(range.commonAncestorContainer)) return null;
  const sizes = new Set<number>();
  const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
  let textNode = walker.nextNode();
  while (textNode) {
    if (textNode.textContent?.trim() && range.intersectsNode(textNode)) {
      const size = Math.round(parseFloat(window.getComputedStyle(textNode.parentElement || editor).fontSize));
      if (size) sizes.add(size);
    }
    textNode = walker.nextNode();
  }
  return sizes.size > 1 ? "mixed" : sizes.size === 1 ? [...sizes][0] : null;
}

type ProblemRichTextEditorProps = {
  value: string;
  label: string;
  onChange: (value: string) => void;
  onOpenExpanded?: () => void;
  expanded?: boolean;
  onDone?: () => void;
  disabled?: boolean;
};

export function ProblemRichTextEditor({ value, label, onChange, onOpenExpanded, expanded = false, onDone, disabled = false }: ProblemRichTextEditorProps) {
  const { t } = useLanguage("teacher");
  const [markdownMode, setMarkdownMode] = useState(false);
  const markdownRef = useRef<HTMLTextAreaElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [fileError, setFileError] = useState(false);
  const editorRef = useRef<HTMLDivElement>(null);
  const latestValueRef = useRef(value);
  const [selectedSize, setSelectedSize] = useState<number | "mixed" | null>(null);
  const [formats, setFormats] = useState(emptyInlineFormats);
  const [spacingOpen, setSpacingOpen] = useState(false);
  const [spacing, setSpacing] = useState<ParagraphSpacing>({line:1.65,before:0,after:0});
  const spacingRef = useRef<HTMLDivElement>(null);
  const spacingButton = useRef<HTMLButtonElement>(null);
  const savedSelection = useRef<Range | null>(null);
  latestValueRef.current = value;
  const labels = {
    undo: t("Undo"), redo: t("Redo"), bold: t("Bold"), italic: t("Italic"), underline:t("Underline"), code: t("Inline code"),
    bullets: t("Bulleted list"), numbers: t("Numbered list"), heading2: t("Heading 2"), heading3: t("Heading 3"),
    link: t("Insert link"), rule: t("Horizontal rule"), decrease: t("Decrease selected text size"),
    increase: t("Increase selected text size"),
  };

  useEffect(() => {
    if (!spacingOpen) return;
    const outside = (event: PointerEvent) => {if (!spacingRef.current?.contains(event.target as Node)) setSpacingOpen(false);};
    document.addEventListener("pointerdown",outside);
    return () => document.removeEventListener("pointerdown",outside);
  },[spacingOpen]);

  const syncFromValue = () => {
    const editor = editorRef.current;
    if (!editor || editor === document.activeElement) return;
    if (editorHtmlToMarkdown(editor.innerHTML) !== latestValueRef.current) editor.innerHTML = markdownToEditorHtml(latestValueRef.current);
  };

  useLayoutEffect(syncFromValue, [value, markdownMode]);

  useEffect(() => {
    const editor = editorRef.current;
    if (!editor) return;
    const updateSelection = () => {
      setSelectedSize(getTextSize(editor, window.getSelection()));
      setFormats(readInlineFormats(editor));
    };
    document.addEventListener("selectionchange", updateSelection);
    editor.addEventListener("keyup", updateSelection);
    editor.addEventListener("mouseup", updateSelection);
    editor.addEventListener("focus", updateSelection);
    return () => {
      document.removeEventListener("selectionchange", updateSelection);
      editor.removeEventListener("keyup", updateSelection);
      editor.removeEventListener("mouseup", updateSelection);
      editor.removeEventListener("focus", updateSelection);
    };
  }, [markdownMode]);

  const syncChange = () => {
    const editor = editorRef.current;
    if (!editor) return;
    const next = editorHtmlToMarkdown(editor.innerHTML);
    latestValueRef.current = next;
    onChange(next);
    setSelectedSize(getTextSize(editor, window.getSelection()));
    setFormats(readInlineFormats(editor));
  };

  const runMarkdownCommand = (command: string, argument = "") => {
    const textarea = markdownRef.current;
    if (!textarea) return;
    textarea.focus({preventScroll:true});
    if (command === "undo" || command === "redo") {
      document.execCommand(command);
      onChange(textarea.value);
      return;
    }
    const edit = markdownToolbarEdit(textarea.value, textarea.selectionStart, textarea.selectionEnd, command, argument);
    textarea.setSelectionRange(edit.from, edit.to);
    // Native insertion keeps toolbar edits in the textarea's undo history.
    if (!document.execCommand("insertText", false, edit.replacement)) textarea.setRangeText(edit.replacement);
    latestValueRef.current = textarea.value;
    onChange(textarea.value);
    textarea.setSelectionRange(edit.selectionStart, edit.selectionEnd);
  };

  const runCommand = (command: string, valueArg?: string) => {
    if (markdownMode) {runMarkdownCommand(command, valueArg); return;}
    const editor = editorRef.current;
    if (!editor) return;
    editor.focus({ preventScroll: true });
    document.execCommand(command, false, valueArg);
    syncChange();
  };

  const wrapInlineCode = () => {
    if (markdownMode) {runMarkdownCommand("code"); return;}
    const editor = editorRef.current;
    if (!editor) return;
    editor.focus({ preventScroll: true });
    toggleInlineCode(editor);
    syncChange();
  };

  const adjustTextSize = (delta: number) => {
    const editor = editorRef.current;
    if (editor && adjustSelectedFontSize(editor, delta) !== null) syncChange();
  };

  const openSpacing = () => {
    const editor=editorRef.current, selection=window.getSelection();
    if (editor && selection?.rangeCount && editor.contains(selection.getRangeAt(0).commonAncestorContainer)) {
      savedSelection.current=selection.getRangeAt(0).cloneRange();
      setSpacing(readParagraphSpacing(editor));
    }
    setSpacingOpen(value => !value);
  };
  const applySpacing = (patch: Partial<ParagraphSpacing>) => {
    const editor=editorRef.current, selection=window.getSelection();
    if(!editor || !selection || !savedSelection.current) return;
    editor.focus({preventScroll:true});
    selection.removeAllRanges();selection.addRange(savedSelection.current);
    if(setParagraphSpacing(editor,patch)) {
      savedSelection.current=selection.getRangeAt(0).cloneRange();
      setSpacing(readParagraphSpacing(editor));syncChange();
    }
  };

  const insertLink = () => {
    if (markdownMode) {
      const rawHref = window.prompt(t("Enter a link URL"), "https://");
      const href = rawHref && safeHref(rawHref);
      if (href) runMarkdownCommand("createLink", href);
      return;
    }
    const editor = editorRef.current;
    if (!editor) return;
    const selection = window.getSelection();
    const selected = selection?.toString() || "";
    const rawHref = window.prompt(t("Enter a link URL"), "https://");
    if (!rawHref) return;
    const href = safeHref(rawHref);
    if (!href) return;
    editor.focus({ preventScroll: true });
    if (!selected && selection?.rangeCount) {
      document.execCommand("insertText", false, href);
      const currentSelection = window.getSelection();
      if (currentSelection?.rangeCount) {
        const range = currentSelection.getRangeAt(0);
        range.setStart(range.startContainer, Math.max(0, range.startOffset - href.length));
        currentSelection.removeAllRanges();
        currentSelection.addRange(range);
      }
    }
    document.execCommand("createLink", false, href);
    syncChange();
  };

  const toolbarButton = (title: string, content: ReactNode, action: () => void, shortcut = "", pressed?: boolean) => (
    <button
      key={title}
      type="button"
      aria-label={title}
      aria-pressed={markdownMode ? undefined : pressed}
      data-tooltip={title}
      data-shortcut={shortcut}
      disabled={disabled || (markdownMode && (title === labels.decrease || title === labels.increase))}
      onMouseDown={(event) => event.preventDefault()}
      onClick={action}
    >{typeof content === "string" ? <TranslatedText>{content}</TranslatedText> : content}</button>
  );

  return (
    <div className={`teacher-rich-text-field${spacingOpen ? " has-spacing-menu" : ""}`}>
      <GlidingTooltipGroup className={`teacher-markdown-toolbar teacher-markdown-toolbar-inline${markdownMode ? " is-markdown-mode" : ""}`} label={t("Text formatting")}>
        {toolbarButton(labels.undo, <Undo2 size={15} />, () => runCommand("undo"), "Ctrl+Z")}
        {toolbarButton(labels.redo, <Redo2 size={15} />, () => runCommand("redo"), "Ctrl+Y")}
        <span className="teacher-markdown-toolbar-divider" aria-hidden="true" />
        {toolbarButton(labels.bold, <Bold />, () => runCommand("bold"), "Ctrl+B", formats.bold)}
        {toolbarButton(labels.italic, <Italic />, () => runCommand("italic"), "Ctrl+I", formats.italic)}
        {toolbarButton(labels.underline, <Underline />, () => runCommand("underline"), "Ctrl+U", formats.underline)}
        {toolbarButton(labels.code, <Code2 />, wrapInlineCode, "", formats.code)}
        <span className="teacher-markdown-toolbar-divider" aria-hidden="true" />
        {toolbarButton(labels.bullets, <List />, () => runCommand("insertUnorderedList"))}
        {toolbarButton(labels.numbers, <ListOrdered />, () => runCommand("insertOrderedList"))}
        <div className="teacher-paragraph-spacing" ref={spacingRef} onKeyDown={event => {
          if(event.key==="Escape") {event.stopPropagation();setSpacingOpen(false);spacingButton.current?.focus();}
        }}>
          <button type="button" ref={spacingButton} aria-label={t("Line and paragraph spacing")} aria-haspopup="dialog" aria-expanded={spacingOpen} disabled={disabled || markdownMode}
            data-tooltip={t("Line and paragraph spacing")} onMouseDown={event=>event.preventDefault()} onClick={openSpacing}>
            <AlignVerticalSpaceAround size={16}/><ChevronDown size={11}/>
          </button>
          {spacingOpen && <div className="teacher-paragraph-menu" role="dialog" aria-label={t("Line and paragraph spacing")}>
            <strong><TranslatedText>{t("Line spacing")}</TranslatedText></strong>
            <div className="teacher-line-spacing-options" role="group" aria-label={t("Line spacing")}>
              {LINE_SPACING.map(line=><button type="button" key={line} aria-pressed={spacing.line===line} onMouseDown={event=>event.preventDefault()} onClick={()=>applySpacing({line})}>{String(line)}</button>)}
            </div>
            {(["before","after"] as const).map(field=><label key={field}><TranslatedText>{t(field==="before"?"Space before paragraph":"Space after paragraph")}</TranslatedText>
              <Select aria-label={t(field==="before"?"Space before paragraph":"Space after paragraph")} value={spacing[field]} onChange={event=>applySpacing({[field]:Number(event.target.value)})}>
                {[...new Set([...PARAGRAPH_SPACING,spacing[field]])].sort((a,b)=>a-b).map(value=><option key={value} value={value} label={t("{value0} pt", {value0:value})}/>)}
              </Select>
            </label>)}
            <small><TranslatedText>{t("Applies to the current paragraph or selected paragraphs.")}</TranslatedText></small>
          </div>}
        </div>
        <span className="teacher-markdown-toolbar-divider" aria-hidden="true" />
        {toolbarButton(labels.heading2, <Heading2 />, () => runCommand("formatBlock", "<h2>"))}
        {toolbarButton(labels.heading3, <Heading3 />, () => runCommand("formatBlock", "<h3>"))}
        {toolbarButton(labels.link, <Link2 size={15} />, insertLink)}
        {toolbarButton(labels.rule, <Minus size={16} />, () => runCommand("insertHorizontalRule"))}
        <span className="teacher-markdown-toolbar-divider" aria-hidden="true" />
        <output className="teacher-markdown-toolbar-size" aria-live="polite" title={t("Selected text size")}>
          <TranslatedText>{selectedSize === "mixed" ? t("Mixed") : selectedSize ? `${selectedSize}px` : "—"}</TranslatedText>
        </output>
        {toolbarButton(labels.decrease, "A−", () => adjustTextSize(-1))}
        {toolbarButton(labels.increase, "A+", () => adjustTextSize(1))}
        <span className="teacher-markdown-toolbar-spacer" aria-hidden="true" />
        <button type="button" className="teacher-markdown-action teacher-markdown-toggle" disabled={disabled} role="switch" aria-label={t("Markdown mode")} data-tooltip="Chế độ Markdown" aria-checked={markdownMode} onClick={() => {setSpacingOpen(false); setMarkdownMode(!markdownMode);}}><span><TranslatedText>{t("Markdown")}</TranslatedText></span><span className="teacher-markdown-switch" aria-hidden="true"><span /></span></button>
        <button type="button" className="teacher-markdown-import" aria-label={t("Use a Markdown file")} data-tooltip="Dùng file Markdown" disabled={disabled} onClick={() => fileRef.current?.click()}><FileUp size={15} aria-hidden="true" /></button>
        <input ref={fileRef} type="file" accept=".md,.markdown,text/markdown,text/plain" hidden onChange={async event => {
          const file = event.target.files?.[0];
          event.target.value = "";
          if (!file) return;
          try { const text = await file.text(); latestValueRef.current = text; onChange(text); setFileError(false); }
          catch { setFileError(true); }
        }} />
        {onOpenExpanded && !expanded && (
          <button type="button" className="teacher-rich-text-expand" disabled={disabled} onClick={onOpenExpanded} aria-label={t("Open editor")} data-tooltip={t("Open editor")}>
            <Maximize2 size={15} aria-hidden="true" />
          </button>
        )}
        {expanded && onDone && (
          <button className="button primary teacher-long-text-done" type="button" onClick={onDone}>
            <TranslatedText>{t("Done")}</TranslatedText>
          </button>
        )}
      </GlidingTooltipGroup>
      {fileError && <p role="alert"><TranslatedText>{t("Could not read the Markdown file. Please try again.")}</TranslatedText></p>}
      {markdownMode ? <textarea ref={markdownRef} onKeyDown={event => {
        if (!(event.ctrlKey || event.metaKey) || event.shiftKey) return;
        const command = ({b:"bold", i:"italic", u:"underline"} as Record<string,string>)[event.key.toLowerCase()];
        if (command) {event.preventDefault(); runMarkdownCommand(command);}
      }} className={`teacher-long-text-editor teacher-rich-text-editor ${expanded ? "" : "teacher-problem-inline-editor"}`.trim()} aria-label={label} value={value} disabled={disabled} onChange={event => onChange(event.target.value)} /> : <div
        ref={editorRef}
        className={`teacher-long-text-editor teacher-rich-text-editor ${expanded ? "" : "teacher-problem-inline-editor"}`.trim()}
        aria-label={label}
        aria-multiline="true"
        data-placeholder={t("Write here…")}
        contentEditable={!disabled}
        aria-disabled={disabled}
        role="textbox"
        suppressContentEditableWarning
        onInput={syncChange}
        onBlur={syncFromValue}
        onKeyDown={(event) => {
          if (!(event.ctrlKey || event.metaKey) || event.shiftKey) return;
          const key = event.key.toLowerCase();
          if (key === "b" || key === "i" || key === "u" || key === "z" || key === "y") {
            event.preventDefault();
            runCommand(key === "b" ? "bold" : key === "i" ? "italic" : key === "u" ? "underline" : key === "z" ? "undo" : "redo");
          }
        }}
      />}
    </div>
  );
}
