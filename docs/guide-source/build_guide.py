# Builds ILL-System-Guide.docx from part1..part5.
import sys
sys.path.insert(0, '.')
from docbuild import Guide
import part1, part2, part3, part4, part5

g = Guide()
g.footer("ILL System — Installation, Configuration and Operations Guide")
for part in (part1, part2, part3, part4, part5):
    part.build(g)
out = sys.argv[1] if len(sys.argv) > 1 else 'ILL-System-Guide.docx'
g.save(out)
print('saved', out)
