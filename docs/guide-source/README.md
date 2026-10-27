# ILL System guide — source

`../ILL-System-Guide.docx` is generated from these files. To change the guide, edit `part1.py` … `part5.py`
(one per group of chapters) and rebuild:

```bash
python3 -m venv venv && ./venv/bin/pip install python-docx
./venv/bin/python build_guide.py ../ILL-System-Guide.docx
```

`docbuild.py` holds the layout helpers (headings, tables, steps, code blocks, notes, table of contents).
Keep the guide generic: no organization names, addresses or branding — use placeholders such as
`example.org` and `abc`. Word fills in the table of contents page numbers when the file is opened.
