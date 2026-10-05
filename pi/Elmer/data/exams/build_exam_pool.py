#!/usr/bin/env python3
"""Build RigPi's single-question study pools and official figures from NCVEC DOCX files."""

import json
import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

NS={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
HEADER_RE=re.compile(r'^([TGE]\d[A-Z]\d{2})\s+\(([ABCD])\)(?:\s+\[([^]]+)\])?\s*$')
CHOICE_RE=re.compile(r'^([ABCD])\.\s*(.*)$')
GROUP_RE=re.compile(r'^([TGE]\d[A-Z])\s+(.+)$')
FIGURE_RE=re.compile(r'\b(?:figure|diagram|illustration|graph|schematic|symbol)\s+(?:[TGE]-?)?\d',re.I)
FIGURE_ID_RE=re.compile(r'\b(?:figure|diagram|illustration|graph|schematic|symbol)\s+([TGE])[- ]?(\d(?:-?\d)?)',re.I)

POOLS={
    'technician':{
        'file':'technician-2026-2030.docx','element':2,'prefix':'T',
        'title':'2026-2030 Technician Class Question Pool',
        'effective_from':'2026-07-01','effective_to':'2030-06-30','errata':'2026-02-19',
        'source_url':'https://ncvec.org/downloads/2026-2030%20Technician%20Pool%20and%20Syllabus%20Public%20Release%20Feb%2019%202026.docx',
        'figures':{'T-1':'image1.jpeg','T-2':'image2.jpeg','T-3':'image3.jpeg'},
    },
    'general':{
        'file':'general-2023-2027.docx','element':3,'prefix':'G',
        'title':'2023-2027 General Class Question Pool',
        'effective_from':'2023-07-01','effective_to':'2027-06-30','errata':'2026-02-04',
        'source_url':'https://ncvec.org/downloads/General%20Class%20Pool%20and%20Syllabus%202023-2027%20Public%20Release%20with%206th%20Errata%20Feb%204%202026.docx',
        'figures':{'G7-1':'image1.png'},
    },
    'extra':{
        'file':'extra-2024-2028.docx','element':4,'prefix':'E',
        'title':'2024-2028 Extra Class Question Pool',
        'effective_from':'2024-07-01','effective_to':'2028-06-30','errata':'2026-02-04',
        'source_url':'https://ncvec.org/downloads/2024-2028%20Extra%20Class%20Question%20Pool%20and%20Syllabus%20Public%20Release%20with%204th%20Errata%20Feb%204%202026.docx',
        'figures':{
            'E5-1':'image1.png','E6-1':'image2.png','E6-2':'image3.png','E6-3':'image4.png',
            'E7-1':'image5.png','E7-2':'image6.png','E7-3':'image7.png','E9-1':'image8.png',
            'E9-2':'image9.png','E9-3':'image10.png',
        },
    },
}


def paragraphs(path):
    with zipfile.ZipFile(path) as archive:
        root=ET.fromstring(archive.read('word/document.xml'))
    result=[]
    for paragraph in root.findall('.//w:p',NS):
        text=''.join(node.text or '' for node in paragraph.findall('.//w:t',NS))
        text=' '.join(text.replace('\xa0',' ').split())
        if text:
            result.append(text)
    return result


def question_figure(text):
    match=FIGURE_ID_RE.search(text)
    if not match:
        return ''
    digits=match.group(2).replace('-','')
    if len(digits)==1:
        return f'{match.group(1).upper()}-{digits}'
    return f'{match.group(1).upper()}{digits[0]}-{digits[1:]}'


def extract_figures(root,license_class,metadata):
    destination=root/'figures'/license_class
    destination.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(root/metadata['file']) as archive:
        for label,source_name in metadata['figures'].items():
            suffix=Path(source_name).suffix.lower()
            target=destination/f'{label}{suffix}'
            target.write_bytes(archive.read('word/media/'+source_name))


def parse_pool(root,license_class,metadata):
    lines=paragraphs(root/metadata['file'])
    groups={}
    for line in lines:
        match=GROUP_RE.match(line)
        if match and match.group(1).startswith(metadata['prefix']):
            groups.setdefault(match.group(1),match.group(2))

    questions={}
    index=0
    while index<len(lines):
        header=HEADER_RE.match(lines[index])
        if not header or not header.group(1).startswith(metadata['prefix']):
            index+=1
            continue
        question_id,correct,reference=header.groups()
        index+=1
        question_parts=[]
        choices={}
        current=None
        while index<len(lines) and not HEADER_RE.match(lines[index]):
            line=lines[index]
            if line=='~~':
                index+=1
                break
            choice=CHOICE_RE.match(line)
            if choice:
                current=choice.group(1)
                choices[current]=choice.group(2)
            elif current:
                choices[current]=(choices[current]+' '+line).strip()
            else:
                question_parts.append(line)
            index+=1
        question=' '.join(question_parts).strip()
        ordered=[choices.get(letter,'').strip() for letter in 'ABCD']
        if not question or any(not item for item in ordered):
            continue
        figure_label=question_figure(question+' '+' '.join(ordered))
        source_figure=metadata['figures'].get(figure_label,'')
        if bool(FIGURE_RE.search(question+' '+' '.join(ordered))) and not source_figure:
            raise ValueError(f'{question_id} refers to unknown figure {figure_label or "(unparsed)"}')
        figure_file=f'{license_class}/{figure_label}{Path(source_figure).suffix.lower()}' if source_figure else ''
        questions[question_id]={
            'id':question_id,
            'group':question_id[:3],
            'topic':groups.get(question_id[:3],''),
            'question':question,
            'choices':ordered,
            'correct_letter':correct,
            'fcc_reference':reference or '',
            'has_figure':bool(figure_file),
            'figure_label':f'Figure {figure_label}' if figure_label else '',
            'figure_file':figure_file,
        }

    usable=list(questions.values())
    usable.sort(key=lambda item:item['id'])
    return {
        'schema_version':1,
        'license_class':license_class,
        'element':metadata['element'],
        'title':metadata['title'],
        'effective_from':metadata['effective_from'],
        'effective_to':metadata['effective_to'],
        'errata_date':metadata['errata'],
        'provider':'NCVEC Question Pool Committee',
        'source_url':metadata['source_url'],
        'notice':'Official pool wording, answer key, and figures from the current NCVEC release.',
        'questions':usable,
        'question_count':len(usable),
        'figure_question_count':sum(1 for question in questions.values() if question['has_figure']),
    }


def main():
    root=Path(__file__).resolve().parent
    output={'schema_version':1,'generated_from':'Official NCVEC DOCX releases','pools':{}}
    for license_class,metadata in POOLS.items():
        extract_figures(root,license_class,metadata)
        output['pools'][license_class]=parse_pool(root,license_class,metadata)
    destination=root/'elmer_exam_pools.json'
    destination.write_text(json.dumps(output,ensure_ascii=False,separators=(',',':'))+'\n')
    for name,pool in output['pools'].items():
        print(f"{name}: {pool['question_count']} questions; {pool['figure_question_count']} use figures")
    print(destination)


if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print(error,file=sys.stderr)
        raise
